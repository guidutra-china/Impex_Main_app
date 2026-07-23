<?php

namespace App\Domain\TradeFairs\Actions;

use App\Domain\Catalog\Actions\SyncProductPrimaryImageAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Actions\ResolveOrCreateCompanyAction;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\TradeFairs\DataTransferObjects\FairProductData;
use App\Domain\TradeFairs\DataTransferObjects\FairSupplierData;
use App\Domain\TradeFairs\DataTransferObjects\RegisterFairSupplierResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Idempotent upsert used by the offline fair PWA. A company captured on a device
 * is created once and identified by its client_uuid; subsequent syncs (or edits)
 * resolve it by client_uuid, or by server id when editing a company that was
 * fetched from the server (e.g. registered by another device or the admin panel).
 * Products are upserted/deleted the same way, so the device only re-sends what
 * actually changed. Mirrors App\Domain\Travel\Actions\StoreTripAction.
 */
class SyncFairCompanyAction
{
    public function execute(FairSupplierData $data): RegisterFairSupplierResult
    {
        return DB::transaction(function () use ($data) {
            [$company, $reused] = $this->resolveCompany($data);

            // null = not sent (leave as is); array = authoritative set (add + remove).
            if ($data->categoryIds !== null) {
                $company->categories()->sync($data->categoryIds);
            }

            // Contact is optional — only touch it when a name was provided.
            if (filled($data->contactName)) {
                $company->contacts()->updateOrCreate(
                    ['is_primary' => true],
                    [
                        'name' => $data->contactName,
                        'email' => $data->contactEmail,
                        'phone' => $data->contactPhone,
                        'wechat' => $data->contactWechat,
                    ],
                );
            }

            $this->storeCompanyPhotos($company, $data->companyPhotos, $company->photos()->count());

            $productNames = [];
            foreach ($data->products as $product) {
                $this->upsertProduct($company, $product);
                $productNames[] = $product->name;
            }

            $this->deleteProducts($company, $data->deletedProductIds, $data->deletedProductUuids);

            return new RegisterFairSupplierResult(
                company: $company->fresh(),
                productNames: $productNames,
                companyReused: $reused,
            );
        });
    }

    /**
     * @return array{0: Company, 1: bool} the resolved company and whether it pre-existed
     */
    private function resolveCompany(FairSupplierData $data): array
    {
        $company = null;

        if ($data->serverId !== null) {
            $company = Company::find($data->serverId);
        } elseif ($data->existingCompanyId !== null) {
            $company = Company::find($data->existingCompanyId);
        } elseif ($data->clientUuid !== null) {
            $company = Company::where('client_uuid', $data->clientUuid)->first();
        }

        if ($company !== null) {
            $this->updateCompanyFields($company, $data);

            return [$company, true];
        }

        // No identity match — resolve by tax_number/normalized name before
        // creating, so the same supplier captured on two devices (distinct
        // client_uuids) does not become two companies.
        $resolved = app(ResolveOrCreateCompanyAction::class)->execute($data->companyName, [
            'client_uuid' => $data->clientUuid,
            'address_city' => $data->addressCity,
            'address_country' => $data->addressCountry,
            'status' => CompanyStatus::PROSPECT,
            'notes' => $data->companyNotes,
            'trade_fair_id' => $data->tradeFairId,
        ], CompanyRole::SUPPLIER);

        if (! $resolved->wasCreated) {
            $this->updateCompanyFields($resolved->company, $data);
        }

        return [$resolved->company, ! $resolved->wasCreated];
    }

    private function updateCompanyFields(Company $company, FairSupplierData $data): void
    {
        $attributes = [];

        // Never blank out a name on reuse/edit; only set when a value is present.
        if (filled($data->companyName)) {
            $attributes['name'] = $data->companyName;
        }
        if ($data->addressCity !== null) {
            $attributes['address_city'] = $data->addressCity;
        }
        if ($data->addressCountry !== null) {
            $attributes['address_country'] = $data->addressCountry;
        }
        if ($data->companyNotes !== null) {
            $attributes['notes'] = $data->companyNotes;
        }
        if ($company->trade_fair_id === null) {
            $attributes['trade_fair_id'] = $data->tradeFairId;
        }
        if ($data->clientUuid !== null && $company->client_uuid === null) {
            $attributes['client_uuid'] = $data->clientUuid;
        }

        if (! empty($attributes)) {
            $company->update($attributes);
        }

        // Ensure the company carries the SUPPLIER role even when reused from CRM.
        $company->companyRoles()->firstOrCreate(['role' => CompanyRole::SUPPLIER->value]);
    }

    private function upsertProduct(Company $company, FairProductData $data): void
    {
        $companyProduct = null;
        if ($data->serverId !== null) {
            $companyProduct = CompanyProduct::where('company_id', $company->id)
                ->where('id', $data->serverId)
                ->first();
        } elseif ($data->clientUuid !== null) {
            $companyProduct = CompanyProduct::where('client_uuid', $data->clientUuid)->first();
        }

        if ($companyProduct !== null) {
            $product = $companyProduct->product;
            $product?->update([
                'name' => $data->name,
                'category_id' => $data->categoryId,
                'description' => $data->description,
            ]);

            $companyProduct->update([
                'unit_price' => $data->unitPrice !== null ? Money::toMinor($data->unitPrice) : 0,
                'currency_code' => $data->currencyCode,
                'moq' => $data->moq,
            ]);

            if ($product !== null) {
                $this->deleteProductImages($product, $data->deletedImageIds);
                $this->storeProductImages($product, $data->photos, markFirstPrimary: false);
                app(SyncProductPrimaryImageAction::class)->execute($product);
            }

            return;
        }

        $this->createProduct($company, $data);
    }

    private function createProduct(Company $company, FairProductData $data): void
    {
        $product = Product::create([
            'name' => $data->name,
            'category_id' => $data->categoryId,
            'description' => $data->description,
            'status' => ProductStatus::DRAFT,
        ]);

        $firstPhotoPath = $this->storeProductImages($product, $data->photos, markFirstPrimary: true);

        $attributes = [
            'client_uuid' => $data->clientUuid,
            'company_id' => $company->id,
            'product_id' => $product->id,
            'role' => 'supplier',
            'unit_price' => $data->unitPrice !== null ? Money::toMinor($data->unitPrice) : 0,
            'currency_code' => $data->currencyCode,
            'moq' => $data->moq,
        ];

        // Only set the pivot avatar when there is a photo — avatar_disk is
        // NOT NULL (defaults to 'public'), so passing null would violate it.
        if ($firstPhotoPath !== null) {
            $attributes['avatar_path'] = $firstPhotoPath;
            $attributes['avatar_disk'] = 'public';
        }

        CompanyProduct::create($attributes);

        if ($firstPhotoPath !== null) {
            app(SyncProductPrimaryImageAction::class)->execute($product);
        }
    }

    /**
     * Delete product lines the device removed. Removes the pivot and the
     * underlying draft product; if the product is protected (referenced in
     * active documents) we keep it and only detach the pivot.
     *
     * @param  array<int, int>  $ids  company_product ids
     * @param  array<int, string>  $uuids  company_product client_uuids
     */
    private function deleteProducts(Company $company, array $ids, array $uuids): void
    {
        $lines = collect();
        if (! empty($ids)) {
            $lines = $lines->merge(
                CompanyProduct::where('company_id', $company->id)->whereIn('id', $ids)->get()
            );
        }
        if (! empty($uuids)) {
            $lines = $lines->merge(
                CompanyProduct::where('company_id', $company->id)->whereIn('client_uuid', $uuids)->get()
            );
        }

        foreach ($lines->unique('id') as $line) {
            $product = $line->product;
            $line->delete();

            if ($product === null) {
                continue;
            }

            try {
                $product->delete();
            } catch (Throwable) {
                // Product is referenced elsewhere — leave it, the pivot is gone.
            }
        }
    }

    /**
     * @param  array<int, int>  $imageIds
     */
    private function deleteProductImages(Product $product, array $imageIds): void
    {
        if (empty($imageIds)) {
            return;
        }

        $product->images()->whereIn('id', $imageIds)->get()->each->delete();
    }

    /**
     * Append product gallery images. Returns the path of the first stored image
     * (used as the pivot avatar on create).
     *
     * @param  array<int, UploadedFile|string>  $photos
     */
    private function storeProductImages(Product $product, array $photos, bool $markFirstPrimary): ?string
    {
        $sortOrder = (int) $product->images()->max('sort_order');
        if ($product->images()->exists()) {
            $sortOrder++;
        }

        $firstPath = null;
        foreach ($photos as $photo) {
            $path = $this->storeFile($photo, 'fair-products');
            if ($path === null) {
                continue;
            }

            $product->images()->create([
                'disk' => 'public',
                'path' => $path,
                'sort_order' => $sortOrder,
                'is_primary' => $markFirstPrimary && $sortOrder === 0,
            ]);

            $firstPath ??= $path;
            $sortOrder++;
        }

        return $firstPath;
    }

    /**
     * @param  array<int, UploadedFile|string>  $photos
     */
    private function storeCompanyPhotos(Company $company, array $photos, int $startOrder = 0): void
    {
        $sortOrder = $startOrder;

        foreach ($photos as $photo) {
            $path = $this->storeFile($photo, 'company-photos');
            if ($path === null) {
                continue;
            }

            $company->photos()->create([
                'disk' => 'public',
                'path' => $path,
                'sort_order' => $sortOrder,
                'is_primary' => $sortOrder === 0,
            ]);

            $sortOrder++;
        }
    }

    private function storeFile(UploadedFile|string|null $file, string $prefix): ?string
    {
        if ($file === null) {
            return null;
        }

        if (is_string($file)) {
            return $file !== '' ? $file : null;
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $filename = $prefix.'/'.Str::uuid().'.'.$extension;

        Storage::disk('public')->put(
            $filename,
            file_get_contents($file->getRealPath()),
        );

        return $filename;
    }
}
