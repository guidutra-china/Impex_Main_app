<?php

namespace App\Domain\TradeFairs\DataTransferObjects;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FairSupplierData
{
    /**
     * @param  array<int>  $categoryIds
     * @param  array<int, UploadedFile|string>  $companyPhotos
     * @param  array<int, FairProductData>  $products
     * @param  array<int, string>  $deletedProductUuids
     * @param  array<int, int>  $deletedProductIds
     */
    public function __construct(
        public readonly int $tradeFairId,
        public readonly ?int $existingCompanyId,
        public readonly string $companyName,
        public readonly ?string $addressCity,
        public readonly ?string $addressCountry,
        public readonly ?string $companyNotes,
        public readonly array $categoryIds,
        public readonly ?string $contactName,
        public readonly ?string $contactEmail,
        public readonly ?string $contactPhone,
        public readonly ?string $contactWechat,
        public readonly array $companyPhotos,
        public readonly array $products,
        // Offline-sync identity + deletions (null/empty for legacy create-only payloads).
        public readonly ?string $clientUuid = null,
        public readonly ?int $serverId = null,
        public readonly array $deletedProductUuids = [],
        public readonly array $deletedProductIds = [],
    ) {}
}
