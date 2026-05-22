<?php

namespace App\Domain\TradeFairs\DataTransferObjects;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FairSupplierData
{
    /**
     * @param  array<int>  $categoryIds
     * @param  array<int, FairProductData>  $products
     */
    public function __construct(
        public readonly int $tradeFairId,
        public readonly ?int $existingCompanyId,
        public readonly string $companyName,
        public readonly ?string $addressCity,
        public readonly ?string $addressCountry,
        public readonly ?string $companyNotes,
        public readonly array $categoryIds,
        public readonly string $contactName,
        public readonly ?string $contactEmail,
        public readonly ?string $contactPhone,
        public readonly ?string $contactWechat,
        public readonly UploadedFile|string|null $businessCardPhoto,
        public readonly array $products,
    ) {}
}
