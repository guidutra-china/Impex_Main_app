<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\DataTransferObjects\AccountsPayablePeriodGroup;
use App\Domain\Financial\DataTransferObjects\AccountsPayableReport;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the accounts payable report for a client company.
 *
 * Scope: only PaymentScheduleItem rows where payable_type is ProformaInvoice
 * and the PI belongs to the given company_id. Shipment mirror items and
 * PurchaseOrder items are excluded to avoid double counting and to match
 * the client's perspective (they only owe on PIs).
 */
final class AccountsPayableQuery
{
    /**
     * Tolerance (in minor units / cents) below which a remaining balance is
     * treated as "effectively paid" — mirrors the convention used by
     * PaymentScheduleItem::getIsPaidInFullAttribute() to absorb percentage
     * rounding differences.
     */
    private const NEARLY_PAID_THRESHOLD_CENTS = 100;

    /**
     * Day span above which the report groups items by month rather than week.
     */
    private const MONTHLY_GROUPING_THRESHOLD_DAYS = 90;

    public function run(
        int $companyId,
        CarbonImmutable $dateFrom,
        CarbonImmutable $dateTo,
        bool $includePaid,
        bool $includeOverdue,
    ): AccountsPayableReport {
        $piIds = ProformaInvoice::query()
            ->where('company_id', $companyId)
            ->pluck('id');

        if ($piIds->isEmpty()) {
            return $this->emptyReport($dateFrom, $dateTo);
        }

        $base = PaymentScheduleItem::query()
            ->where('payable_type', ProformaInvoice::class)
            ->whereIn('payable_id', $piIds)
            ->where('is_credit', false)
            ->with('payable');

        $openStatuses = [
            PaymentScheduleStatus::PENDING,
            PaymentScheduleStatus::DUE,
            PaymentScheduleStatus::OVERDUE,
        ];

        $today = CarbonImmutable::now()->startOfDay();

        // Overdue items (only if toggle is on): due_date < today and not resolved
        $overdueItems = collect();
        if ($includeOverdue) {
            $overdueItems = (clone $base)
                ->whereIn('status', $openStatuses)
                ->whereDate('due_date', '<', $today)
                ->orderBy('due_date')
                ->get()
                ->filter(fn (PaymentScheduleItem $item) => $item->remaining_amount > 0)
                ->values();
        }

        // Period items: due_date within [dateFrom, dateTo]
        $periodQuery = (clone $base)
            ->whereBetween('due_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        if ($includePaid) {
            // All statuses allowed
        } else {
            $periodQuery->whereIn('status', $openStatuses);
        }

        $periodItems = $periodQuery
            ->orderBy('due_date')
            ->get()
            ->reject(
                fn (PaymentScheduleItem $item) => ! $includePaid && $item->remaining_amount <= self::NEARLY_PAID_THRESHOLD_CENTS
            )
            ->values();

        $groupingMode = $this->resolveGroupingMode($dateFrom, $dateTo);

        $periodGroups = $this->groupItems($periodItems, $groupingMode);

        $overdueTotals = $this->totalsByCurrency($overdueItems);
        $periodTotals = $this->totalsByCurrency($periodItems);
        $grandTotals = $this->mergeTotals($overdueTotals, $periodTotals);

        return new AccountsPayableReport(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            groupingMode: $groupingMode,
            overdueItems: $overdueItems,
            overdueTotalsByCurrency: $overdueTotals,
            periodGroups: $periodGroups,
            periodTotalsByCurrency: $periodTotals,
            grandTotalsByCurrency: $grandTotals,
        );
    }

    private function emptyReport(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): AccountsPayableReport
    {
        $groupingMode = $this->resolveGroupingMode($dateFrom, $dateTo);

        return new AccountsPayableReport(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            groupingMode: $groupingMode,
            overdueItems: collect(),
            overdueTotalsByCurrency: [],
            periodGroups: collect(),
            periodTotalsByCurrency: [],
            grandTotalsByCurrency: [],
        );
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return Collection<int, AccountsPayablePeriodGroup>
     */
    private function groupItems(Collection $items, string $mode): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        return $items
            ->groupBy(function (PaymentScheduleItem $item) use ($mode): string {
                $d = CarbonImmutable::parse($item->due_date);

                return $mode === 'month'
                    ? $d->format('Y-m')
                    : $d->startOfWeek()->toDateString();
            })
            ->map(function (Collection $bucket, string $key) use ($mode) {
                $first = CarbonImmutable::parse($bucket->first()->due_date);
                [$start, $end, $label] = $mode === 'month'
                    ? [
                        $first->startOfMonth(),
                        $first->endOfMonth(),
                        $first->translatedFormat('F Y'),
                    ]
                    : [
                        $first->startOfWeek(),
                        $first->endOfWeek(),
                        $first->startOfWeek()->format('d/m').' – '.$first->endOfWeek()->format('d/m'),
                    ];

                return new AccountsPayablePeriodGroup(
                    label: $label,
                    startDate: $start,
                    endDate: $end,
                    items: $bucket->values(),
                    totalsByCurrency: $this->totalsByCurrency($bucket),
                );
            })
            ->values();
    }

    /**
     * @param  Collection<int, PaymentScheduleItem>  $items
     * @return array<string, int>
     */
    private function totalsByCurrency(Collection $items): array
    {
        return $items
            ->groupBy('currency_code')
            ->map(fn (Collection $bucket) => (int) $bucket->sum('remaining_amount'))
            ->all();
    }

    private function resolveGroupingMode(CarbonImmutable $dateFrom, CarbonImmutable $dateTo): string
    {
        $days = (int) $dateFrom->diffInDays($dateTo, absolute: true);

        return $days > self::MONTHLY_GROUPING_THRESHOLD_DAYS ? 'month' : 'week';
    }

    /**
     * @param  array<string, int>  $a
     * @param  array<string, int>  $b
     * @return array<string, int>
     */
    private function mergeTotals(array $a, array $b): array
    {
        $result = $a;
        foreach ($b as $currency => $amount) {
            $result[$currency] = ($result[$currency] ?? 0) + $amount;
        }

        return $result;
    }
}
