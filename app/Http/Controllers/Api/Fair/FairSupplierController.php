<?php

namespace App\Http\Controllers\Api\Fair;

use App\Domain\TradeFairs\Actions\RegisterFairSupplierAction;
use App\Domain\TradeFairs\DataTransferObjects\FairProductData;
use App\Domain\TradeFairs\DataTransferObjects\FairSupplierData;
use App\Http\Requests\Api\Fair\StoreFairSupplierRequest;
use Illuminate\Http\JsonResponse;

class FairSupplierController
{
    public function store(StoreFairSupplierRequest $request, RegisterFairSupplierAction $action): JsonResponse
    {
        $validated = $request->validated();

        $products = [];
        foreach ($validated['products'] ?? [] as $idx => $product) {
            $photos = $request->file("products.$idx.photos") ?? [];

            // Fall back to the legacy single-photo field for older payloads.
            if (empty($photos) && $request->file("products.$idx.photo")) {
                $photos = [$request->file("products.$idx.photo")];
            }

            $products[] = new FairProductData(
                name: $product['name'],
                categoryId: (int) $product['category_id'],
                description: $product['description'] ?? null,
                unitPrice: isset($product['unit_price']) ? (float) $product['unit_price'] : null,
                currencyCode: $product['currency_code'] ?? 'USD',
                moq: isset($product['moq']) ? (int) $product['moq'] : null,
                photos: array_values($photos),
            );
        }

        $companyPhotos = $request->file('company_photos') ?? [];

        // Fall back to the legacy single business-card field for older payloads.
        if (empty($companyPhotos) && $request->file('business_card_photo')) {
            $companyPhotos = [$request->file('business_card_photo')];
        }

        $data = new FairSupplierData(
            tradeFairId: (int) $validated['trade_fair_id'],
            existingCompanyId: $validated['existing_company_id'] ?? null,
            companyName: $validated['company_name'] ?? '',
            addressCity: $validated['address_city'] ?? null,
            addressCountry: $validated['address_country'] ?? null,
            companyNotes: $validated['company_notes'] ?? null,
            categoryIds: $validated['category_ids'] ?? [],
            contactName: $validated['contact_name'] ?? '',
            contactEmail: $validated['contact_email'] ?? null,
            contactPhone: $validated['contact_phone'] ?? null,
            contactWechat: $validated['contact_wechat'] ?? null,
            companyPhotos: array_values($companyPhotos),
            products: $products,
        );

        $result = $action->execute($data);

        return response()->json([
            'company' => [
                'id' => $result->company->id,
                'name' => $result->company->name,
                'trade_fair_id' => $result->company->trade_fair_id,
                'photo_count' => $result->company->photos()->count(),
            ],
            'product_names' => $result->productNames,
            'reused_existing_company' => $result->companyReused,
        ], 201);
    }
}
