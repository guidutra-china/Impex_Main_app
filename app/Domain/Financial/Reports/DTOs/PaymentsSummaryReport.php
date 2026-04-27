<?php

namespace App\Domain\Financial\Reports\DTOs;

final class PaymentsSummaryReport
{
    public function __construct(
        public readonly PaymentsSummaryFilters $filters,
        /** @var list<PaymentCurrencyTotal> */
        public readonly array $grandTotalsByCurrency,
        public readonly int $totalPaymentCount,
        /** @var list<PaymentCompanyBucket> */
        public readonly array $byCompany,
        /** @var list<PaymentMonthBucket> */
        public readonly array $byMonth,
        /** @var list<PaymentAllocationDetail> */
        public readonly array $allocations,
    ) {}
}
