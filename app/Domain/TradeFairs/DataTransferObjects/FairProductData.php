<?php

namespace App\Domain\TradeFairs\DataTransferObjects;

final class FairProductData
{
    /**
     * @param  array<int, \Symfony\Component\HttpFoundation\File\UploadedFile|string>  $photos
     * @param  array<int, int>  $deletedImageIds
     */
    public function __construct(
        public readonly string $name,
        public readonly int $categoryId,
        public readonly ?string $description = null,
        public readonly ?float $unitPrice = null,
        public readonly string $currencyCode = 'USD',
        public readonly ?int $moq = null,
        public readonly array $photos = [],
        // Offline-sync identity + image deletions (null/empty for create-only payloads).
        public readonly ?string $clientUuid = null,
        public readonly ?int $serverId = null,
        public readonly array $deletedImageIds = [],
    ) {}
}
