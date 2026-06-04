<?php

namespace App\Domain\Travel\DataTransferObjects;

class TripData
{
    /**
     * @param  array<int, TripExpenseData>  $expenses
     */
    public function __construct(
        public readonly string $title,
        public readonly string $startDate,
        public readonly ?int $companyId = null,
        public readonly bool $isInternal = false,
        public readonly ?int $userId = null,
        public readonly ?string $destinationCity = null,
        public readonly ?string $destinationCountry = null,
        public readonly ?string $endDate = null,
        public readonly ?string $notes = null,
        public readonly ?string $clientUuid = null,
        public readonly array $expenses = [],
        public readonly bool $finalize = false,
        /** @var array<int, string> client_uuids of expenses to delete */
        public readonly array $deletedExpenseUuids = [],
    ) {}
}
