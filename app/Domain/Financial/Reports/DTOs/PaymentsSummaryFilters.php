<?php

namespace App\Domain\Financial\Reports\DTOs;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use Carbon\CarbonImmutable;

final class PaymentsSummaryFilters
{
    public function __construct(
        public readonly PaymentDirection $direction,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        /** @var list<int> */
        public readonly array $companyIds = [],
        /** @var list<string> */
        public readonly array $currencies = [],
        public readonly bool $approvedOnly = true,
    ) {}

    /** @return list<PaymentStatus> */
    public function statuses(): array
    {
        if ($this->approvedOnly) {
            return [PaymentStatus::APPROVED];
        }

        return [
            PaymentStatus::APPROVED,
            PaymentStatus::PENDING_APPROVAL,
        ];
    }
}
