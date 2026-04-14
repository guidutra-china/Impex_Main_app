<?php

namespace App\Domain\Financial\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One period bucket (week or month) of accounts payable items.
 * Totals are indexed by currency code (e.g. ['USD' => 123400, 'EUR' => 5000]).
 * Amounts are integers in minor units (cents).
 */
final class AccountsPayablePeriodGroup
{
    public function __construct(
        public readonly string $label,
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        /** @var Collection<int, \App\Domain\Financial\Models\PaymentScheduleItem> */
        public readonly Collection $items,
        /** @var array<string, int> */
        public readonly array $totalsByCurrency,
    ) {
    }

    public function count(): int
    {
        return $this->items->count();
    }
}
