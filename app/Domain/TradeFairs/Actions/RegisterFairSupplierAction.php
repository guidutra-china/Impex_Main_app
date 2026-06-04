<?php

namespace App\Domain\TradeFairs\Actions;

use App\Domain\Catalog\Actions\SyncProductPrimaryImageAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Domain\CRM\Models\Contact;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\TradeFairs\DataTransferObjects\FairProductData;
use App\Domain\TradeFairs\DataTransferObjects\FairSupplierData;
use App\Domain\TradeFairs\DataTransferObjects\RegisterFairSupplierResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RegisterFairSupplierAction
{
    public function execute(FairSupplierData $data): RegisterFairSupplierResult
    {
        return DB::transaction(function () use ($data) {
            $companyReused = false;

            if ($data->existingCompanyId) {
                $company = $this->reuseExistingCompany($data);
                $companyReused = true;
            } else {
                $company = $this->createNewCompany($data);
            }

            $productNames = [];
            foreach ($data->products as $product) {
                $this->createProduct($company, $product);
                $productNames[] = $product->name;
            }

            return new RegisterFairSupplierResult(
                company: $company,
                productNames: $productNames,
                companyReused: $companyReused,
            );
        });
    }

    private function reuseExistingCompany(FairSupplierData $data): Company
    {
        $company = Company::findOrFail($data->existingCompanyId);

        if (! $company->trade_fair_id) {
            $company->update(['trade_fair_id' => $data->tradeFairId]);
        }

        if (! empty($data->companyNotes)) {
            $company->update(['notes' => $data->companyNotes]);
        }

        // Append newly captured photos to the existing gallery without stealing
        // the primary flag from any photo already present.
        $this->storeCompanyPhotos($company, $data->companyPhotos, $company->photos()->count());

        return $company;
    }

    private function createNewCompany(FairSupplierData $data): Company
    {
        $company = Company::create([
            'name' => $data->companyName,
            'address_city' => $data->addressCity,
            'address_country' => $data->addressCountry,
            'status' => CompanyStatus::PROSPECT,
            'notes' => $data->companyNotes,
            'trade_fair_id' => $data->tradeFairId,
        ]);

        CompanyRoleAssignment::create([
            'company_id' => $company->id,
            'role' => CompanyRole::SUPPLIER,
        ]);

        if (! empty($data->categoryIds)) {
            $company->categories()->attach($data->categoryIds);
        }

        Contact::create([
            'company_id' => $company->id,
            'name' => $data->contactName,
            'email' => $data->contactEmail,
            'phone' => $data->contactPhone,
            'wechat' => $data->contactWechat,
            'is_primary' => true,
        ]);

        $this->storeCompanyPhotos($company, $data->companyPhotos);

        return $company;
    }

    /**
     * Persist the company gallery photos as company_photos rows. The photo at
     * sort_order 0 is flagged primary (company cover / OCR source).
     *
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

    private function createProduct(Company $company, FairProductData $data): void
    {
        $product = Product::create([
            'name' => $data->name,
            'category_id' => $data->categoryId,
            'description' => $data->description,
            'status' => ProductStatus::DRAFT,
        ]);

        $firstPhotoPath = null;
        $sortOrder = 0;
        foreach ($data->photos as $photo) {
            $path = $this->storeFile($photo, 'fair-products');
            if ($path === null) {
                continue;
            }

            $product->images()->create([
                'disk' => 'public',
                'path' => $path,
                'sort_order' => $sortOrder,
                'is_primary' => $sortOrder === 0,
            ]);

            $firstPhotoPath ??= $path;
            $sortOrder++;
        }

        CompanyProduct::create([
            'company_id' => $company->id,
            'product_id' => $product->id,
            'role' => 'supplier',
            'unit_price' => $data->unitPrice !== null ? Money::toMinor($data->unitPrice) : 0,
            'currency_code' => $data->currencyCode,
            'moq' => $data->moq,
            // First photo doubles as the supplier-specific avatar (backward compat).
            'avatar_path' => $firstPhotoPath,
            'avatar_disk' => $firstPhotoPath ? 'public' : null,
        ]);

        if ($firstPhotoPath !== null) {
            app(SyncProductPrimaryImageAction::class)->execute($product);
        }
    }

    /**
     * Persist an uploaded file to the public disk under the given prefix,
     * or pass through a pre-existing storage path string unchanged.
     */
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
