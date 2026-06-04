<?php

namespace App\Domain\Travel\DataTransferObjects;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class TripExpenseData
{
    /**
     * @param  array<int, UploadedFile|string>  $photos
     */
    public function __construct(
        public readonly string $category,
        public readonly ?string $description,
        public readonly int $amount,          // minor units (Money scale 10000)
        public readonly string $currencyCode,
        public readonly string $expenseDate,
        public readonly array $photos = [],
        public readonly ?string $clientUuid = null,
    ) {}
}
