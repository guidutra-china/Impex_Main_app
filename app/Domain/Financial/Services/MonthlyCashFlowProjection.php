<?php

namespace App\Domain\Financial\Services;

use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Financial\Support\BaseCurrency;
use App\Domain\Financial\Support\CurrencyTotals;
use App\Domain\Infrastructure\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly cash-flow buckets for the financial dashboard chart: current month
 * plus the next five, in the base currency (major units, ready for Chart.js).
 * Overdue items fold into the current-month bucket — cash still expected.
 */
class MonthlyCashFlowProjection
{
    private const MONTHS = 6;

    /**
     * @return array{
     *     labels: array<int, string>,
     *     inflow: array<int, float>,
     *     outflow: array<int, float>,
     *     net: array<int, float>,
     *     has_warning: bool,
     *     unconverted: array<int, string>,
     * }
     */
    public function build(): array
    {
        $start = CarbonImmutable::now()->startOfMonth();

        $receivables = OpenScheduleItemsQuery::receivables()
            ->without(['payable', 'source', 'shipment'])
            ->whereNotNull('due_date')
            ->get();
        $payables = OpenScheduleItemsQuery::payables()
            ->without(['payable', 'source', 'shipment'])
            ->whereNotNull('due_date')
            ->get();

        $labels = [];
        $inflow = [];
        $outflow = [];
        $net = [];
        $unconverted = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $monthStart = $start->addMonthsNoOverflow($i);
            $monthEnd = $monthStart->endOfMonth();
            $labels[] = $monthStart->isoFormat('MMM/YY');

            $inResult = $this->bucketTotal($receivables, $monthStart, $monthEnd, $i === 0);
            $outResult = $this->bucketTotal($payables, $monthStart, $monthEnd, $i === 0);

            $inflow[] = Money::toMajor($inResult['total']);
            $outflow[] = Money::toMajor($outResult['total']);
            $net[] = round(Money::toMajor($inResult['total']) - Money::toMajor($outResult['total']), 2);

            $unconverted = array_merge($unconverted, $inResult['unconverted'], $outResult['unconverted']);
        }

        $unconverted = array_values(array_unique($unconverted));

        return [
            'labels' => $labels,
            'inflow' => $inflow,
            'outflow' => $outflow,
            'net' => $net,
            'has_warning' => $unconverted !== [],
            'unconverted' => $unconverted,
        ];
    }

    /**
     * @param  Collection<int, \App\Domain\Financial\Models\PaymentScheduleItem>  $items
     * @return array{total: int, unconverted: array<int, string>}
     */
    private function bucketTotal(Collection $items, CarbonImmutable $monthStart, CarbonImmutable $monthEnd, bool $includeOverdue): array
    {
        $bucketItems = $items->filter(function ($item) use ($monthStart, $monthEnd, $includeOverdue) {
            $due = $item->due_date->toImmutable()->startOfDay();

            if ($includeOverdue && $due->lt($monthStart)) {
                return true;
            }

            return $due->gte($monthStart) && $due->lte($monthEnd);
        });

        return BaseCurrency::convert(CurrencyTotals::byCurrency($bucketItems));
    }
}
