<?php

namespace App\Domain\TradeFairs\DataTransferObjects;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FairProductData
{
    public function __construct(
        public readonly string $name,
        public readonly int $categoryId,
        public readonly ?string $description = null,
        public readonly ?float $unitPrice = null,
        public readonly string $currencyCode = 'USD',
        public readonly ?int $moq = null,
        public readonly UploadedFile|string|null $photo = null,
    ) {}
}
