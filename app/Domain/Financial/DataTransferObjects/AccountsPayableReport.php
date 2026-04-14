<?php

namespace App\Domain\Financial\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Full accounts payable report returned by AccountsPayableQuery.
 * All totals are arrays keyed by ISO currency code with integer minor-unit values.
 */
final class AccountsPayableReport
{
    public function __construct(
        public readonly CarbonImmutable $dateFrom,
        public readonly CarbonImmutable $dateTo,
        public readonly string $groupingMode, // 'week' or 'month'
        /** @var Collection<int, \App\Domain\Financial\Models\PaymentScheduleItem> */
        public readonly Collection $overdueItems,
        /** @var array<string, int> */
        public readonly array $overdueTotalsByCurrency,
        /** @var Collection<int, AccountsPayablePeriodGroup> */
        public readonly Collection $periodGroups,
        /** @var array<string, int> */
        public readonly array $periodTotalsByCurrency,
        /** @var array<string, int> */
        public readonly array $grandTotalsByCurrency,
    ) {
    }

    public function hasAnyItems(): bool
    {
        return $this->overdueItems->isNotEmpty() || $this->periodGroups->isNotEmpty();
    }
}
