<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\DataTransferObjects\AccountsPayablePeriodGroup;
use App\Domain\Financial\DataTransferObjects\AccountsPayableReport;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Supplier-facing mirror of AccountsPayableQuery.
 *
 * Scope: PaymentScheduleItem rows that represent amounts the company owes
 * the given supplier. Matches items whose payable is a PurchaseOrder where
 * supplier_company_id = $companyId, or whose source is an AdditionalCost
 * with supplier_company_id = $companyId (shipment-level supplier costs).
 *
 * Items with a NULL due_date are returned in a dedicated "no due date" bucket
 * so they remain visible regardless of the selected period.
 */
final class AccountsReceivableQuery
{
    private const NEARLY_PAID_THRESHOLD_CENTS = 100;

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
        $poIds = PurchaseOrder::query()
            ->where('supplier_company_id', $companyId)
            ->pluck('id');

        $additionalCostIds = AdditionalCost::query()
            ->where('supplier_company_id', $companyId)
            ->pluck('id');

        // Supplier-party Debit Notes: extra amounts Impex owes the supplier,
        // owned by the DN itself (mirrors how PO/cost payables are scoped).
        $debitNoteIds = DebitNote::query()
            ->where('company_id', $companyId)
            ->where('party_type', PartyType::SUPPLIER->value)
            ->where('status', DebitNoteStatus::ISSUED->value)
            ->pluck('id');

        if ($poIds->isEmpty() && $additionalCostIds->isEmpty() && $debitNoteIds->isEmpty()) {
            return $this->emptyReport($dateFrom, $dateTo);
        }

        $base = PaymentScheduleItem::query()
            // Documento pai cancelado → fora do balance, mesmo com parcela aberta.
            ->payableNotCancelled()
            ->where(function ($q) use ($poIds, $additionalCostIds, $debitNoteIds) {
                if ($poIds->isNotEmpty()) {
                    $q->orWhere(function ($sub) use ($poIds) {
                        $sub->where('payable_type', PurchaseOrder::class)
                            ->whereIn('payable_id', $poIds);
                    });
                }
                if ($additionalCostIds->isNotEmpty()) {
                    $q->orWhere(function ($sub) use ($additionalCostIds) {
                        $sub->where('source_type', AdditionalCost::class)
                            ->whereIn('source_id', $additionalCostIds);
                    });
                }
                if ($debitNoteIds->isNotEmpty()) {
                    $q->orWhere(function ($sub) use ($debitNoteIds) {
                        $sub->where('payable_type', DebitNote::class)
                            ->whereIn('payable_id', $debitNoteIds);
                    });
                }
            })
            ->where('is_credit', false)
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
