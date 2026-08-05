<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\DataTransferObjects\AccountsPayablePeriodGroup;
use App\Domain\Financial\DataTransferObjects\AccountsPayableReport;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the accounts payable report for a client company.
 *
 * Scope: PaymentScheduleItem rows whose payable is either a ProformaInvoice
 * or a Shipment belonging to the given company_id. Shipment items are
 * included so that freight, commission, and other additional-cost items
 * scheduled at the shipment level surface in the client's AP view.
 * Items with a NULL due_date (typical for additional-cost items) are
 * returned in a dedicated "no due date" bucket so they remain visible
 * regardless of the selected period.
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
        bool $includeFreight = true,
        bool $includeCommission = true,
    ): AccountsPayableReport {
        $piIds = ProformaInvoice::query()
            ->where('company_id', $companyId)
            ->pluck('id');

        $shipmentIds = Shipment::query()
            ->where('company_id', $companyId)
            ->pluck('id');

        if ($piIds->isEmpty() && $shipmentIds->isEmpty()) {
            return $this->emptyReport($dateFrom, $dateTo);
        }

        $base = PaymentScheduleItem::query()
            // Documento pai cancelado → fora do balance, mesmo com parcela aberta.
            ->payableNotCancelled()
            ->where(function ($q) use ($piIds, $shipmentIds) {
                if ($piIds->isNotEmpty()) {
                    $q->orWhere(function ($sub) use ($piIds) {
                        $sub->where('payable_type', ProformaInvoice::class)
                            ->whereIn('payable_id', $piIds);
                    });
                }
                if ($shipmentIds->isNotEmpty()) {
                    // Shipment-level items: only include AdditionalCost-sourced
                    // rows (freight, commission, etc.). Other Shipment-owned
                    // items are mirrors of PI/PO installments and would
                    // duplicate what already appears under the PI.
                    $q->orWhere(function ($sub) use ($shipmentIds) {
                        $sub->where('payable_type', Shipment::class)
                            ->whereIn('payable_id', $shipmentIds)
                            ->where('source_type', AdditionalCost::class);
                    });
                }
            })
            ->where('is_credit', false)
            // Exclude forwarder-payable and supplier-payable mirror items: those
            // are amounts Impex owes the forwarder/supplier, not amounts the
            // client owes.
            ->withoutSideTags()
            ->with(['payable', 'source']);

        $openStatuses = [
            PaymentScheduleStatus::PENDING,
            PaymentScheduleStatus::DUE,
            PaymentScheduleStatus::OVERDUE,
        ];

        $today = CarbonImmutable::now()->startOfDay();

        $rejectByCostType = fn (PaymentScheduleItem $item): bool => $this->isExcludedByCostType(
            $item,
            $includeFreight,
            $includeCommission,
        );
        $rejectIfEffectivelyPaid = fn (PaymentScheduleItem $item): bool => ! $includePaid
            && $item->remaining_amount <= self::NEARLY_PAID_THRESHOLD_CENTS;

        // Overdue items (only if toggle is on): due_date < today and not resolved
        $overdueItems = collect();
        if ($includeOverdue) {
            $overdueItems = (clone $base)
                ->whereIn('status', $openStatuses)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', $today)
                ->orderBy('due_date')
                ->get()
                ->filter(fn (PaymentScheduleItem $item) => $item->remaining_amount > 0)
                ->reject($rejectByCostType)
                ->values();
        }

        // Items with NULL due_date — additional costs (freight, commission, ...)
        // typically arrive here. Always shown regardless of period filter; only
        // hidden when includePaid=false AND the item is effectively paid.
        $noDueDateQuery = (clone $base)->whereNull('due_date');
        if (! $includePaid) {
            $noDueDateQuery->whereIn('status', $openStatuses);
        }
        $noDueDateItems = $noDueDateQuery
            ->orderBy('id')
            ->get()
            ->reject($rejectIfEffectivelyPaid)
            ->reject($rejectByCostType)
            ->values();

        // Period items: due_date within [dateFrom, dateTo]
        $periodQuery = (clone $base)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$dateFrom->toDateString(), $dateTo->toDateString()]);

        if (! $includePaid) {
            $periodQuery->whereIn('status', $openStatuses);
        }

        $periodItems = $periodQuery
            ->orderBy('due_date')
            ->get()
            ->reject($rejectIfEffectivelyPaid)
            ->reject($rejectByCostType)
            ->values();

        $groupingMode = $this->resolveGroupingMode($dateFrom, $dateTo);

        $periodGroups = $this->groupItems($periodItems, $groupingMode);

        $overdueTotals = $this->totalsByCurrency($overdueItems);
        $noDueDateTotals = $this->totalsByCurrency($noDueDateItems);
        $periodTotals = $this->totalsByCurrency($periodItems);
        $grandTotals = $this->mergeTotals(
            $this->mergeTotals($overdueTotals, $noDueDateTotals),
            $periodTotals,
        );

        return new AccountsPayableReport(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            groupingMode: $groupingMode,
            overdueItems: $overdueItems,
            overdueTotalsByCurrency: $overdueTotals,
            noDueDateItems: $noDueDateItems,
            noDueDateTotalsByCurrency: $noDueDateTotals,
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
            noDueDateItems: collect(),
            noDueDateTotalsByCurrency: [],
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

    /**
     * Returns true if the item should be excluded based on its underlying
     * AdditionalCost cost_type and the freight/commission toggles. Items not
     * sourced from an AdditionalCost (e.g., regular PI/PO payment terms) are
     * never excluded by this filter.
     */
    private function isExcludedByCostType(
        PaymentScheduleItem $item,
        bool $includeFreight,
        bool $includeCommission,
    ): bool {
        if ($item->source_type !== AdditionalCost::class || ! $item->source) {
            return false;
        }

        $type = $item->source->cost_type ?? null;

        return match ($type) {
            AdditionalCostType::FREIGHT => ! $includeFreight,
            AdditionalCostType::COMMISSION => ! $includeCommission,
            default => false,
        };
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
