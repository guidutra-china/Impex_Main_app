<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ShipmentCostSectionBuilder implements FinancialSectionBuilder
{
    public function __construct(private readonly CompanyRole $role) {}

    public function key(): string
    {
        return 'shipments';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $dateExpr = DB::raw('COALESCE(issue_date, etd, created_at)');

        $query = Shipment::query()
            ->whereBetween($dateExpr, [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy($dateExpr);

        match ($this->role) {
            CompanyRole::CLIENT => $query->where('company_id', $company->id),
            CompanyRole::SUPPLIER => $query->whereHas(
                'items.proformaInvoiceItem',
                fn ($q) => $q->where('supplier_company_id', $company->id),
            ),
            default => $query->whereRaw('1 = 0'),
        };

        $scope = StatusScopeFilter::whereClause($filters->statusScope);
        if ($scope !== null) {
            [$op, $values] = $scope;
            $op === 'in'
                ? $query->whereIn('status', $values)
                : $query->whereNotIn('status', $values);
        }

        $rows = [];
        $isClientReport = $this->role === CompanyRole::CLIENT;

        foreach ($query->with(['items.proformaInvoiceItem', 'additionalCosts', 'paymentScheduleItems.allocations.payment'])->get() as $s) {
            if ($filters->isExcluded('shipments', (int) $s->id)) {
                continue;
            }

            $eligibleCosts = $s->additionalCosts
                ->where('billable_to', BillableTo::CLIENT)
                ->reject(fn ($cost) => $filters->isExcluded('additional_costs', (int) $cost->id));

            $freight = $eligibleCosts
                ->where('cost_type', AdditionalCostType::FREIGHT)
                ->sum('amount_in_document_currency');

            $otherCosts = $eligibleCosts
                ->where('cost_type', '!=', AdditionalCostType::FREIGHT)
                ->sum('amount_in_document_currency');

            $totalCosts = $freight + $otherCosts;

            // Filter schedule items by direction:
            // - CLIENT report: exclude forwarder-payable items (those are outbound payments)
            // - SUPPLIER/forwarder report: include only forwarder-payable items
            $relevantScheduleItems = $s->paymentScheduleItems->filter(function ($item) use ($isClientReport) {
                $isForwarderPayable = str_contains((string) $item->notes, '[forwarder-payable]');

                return $isClientReport ? ! $isForwarderPayable : $isForwarderPayable;
            });

            // Use the paid_amount accessor which includes mirror allocations
            // from PI/PO schedule items (users pay on PI/PO, not directly on shipment)
            $paidCosts = 0;
            foreach ($relevantScheduleItems as $item) {
                if ($item->is_credit) {
                    continue;
                }
                $paidCosts += (int) $item->paid_amount;
            }

            // Shipment header row
            $rows[] = [
                '_row_type' => 'header',
                '_entity_id' => (int) $s->id,
                'number' => $s->reference ?? (string) $s->id,
                'bl_number' => (string) ($s->bl_number ?? ''),
                'client_reference' => (string) ($s->client_reference ?? ''),
                'etd' => optional($s->etd)->format('Y-m-d'),
                'eta' => optional($s->eta)->format('Y-m-d'),
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status,
                'freight' => round($freight / Money::SCALE, 2),
                'other_costs' => round($otherCosts / Money::SCALE, 2),
                'total_costs' => round($totalCosts / Money::SCALE, 2),
                'paid' => round($paidCosts / Money::SCALE, 2),
                'balance' => round(($totalCosts - $paidCosts) / Money::SCALE, 2),
                'currency' => (string) ($s->currency_code ?? ''),
            ];

            if (! $filters->showDetails) {
                continue;
            }

            // Schedule item detail rows with allocation breakdown
            $scheduleItems = $relevantScheduleItems->sortBy('sort_order');

            foreach ($scheduleItems as $item) {
                if ($item->is_credit) {
                    continue;
                }

                $itemAmount = (int) $item->amount;
                $itemPaid = (int) $item->paid_amount;
                $allocationDetails = [];

                // Direct allocations on this shipment schedule item
                foreach ($item->allocations as $allocation) {
                    if ($allocation->payment && $allocation->payment->status === PaymentStatus::APPROVED) {
                        $allocAmount = (int) ($allocation->allocated_amount_in_document_currency ?? 0);
                        $allocationDetails[] = $allocation->payment->reference
                            .' ('.optional($allocation->payment->payment_date)->format('d/m/Y').')'
                            .': '.number_format($allocAmount / Money::SCALE, 2);
                    }
                }

                // Mirror allocations from PI/PO schedule item (same stage + shipment)
                foreach ($this->getMirrorAllocations($item) as $allocation) {
                    $allocAmount = (int) ($allocation->allocated_amount_in_document_currency ?? 0);
                    $allocationDetails[] = $allocation->payment->reference
                        .' ('.optional($allocation->payment->payment_date)->format('d/m/Y').')'
                        .' [via '.$this->extractMirrorDocRef($item->label).']'
                        .': '.number_format($allocAmount / Money::SCALE, 2);
                }

                $itemRemaining = max(0, $itemAmount - $itemPaid);

                $allocationsText = empty($allocationDetails)
                    ? '(no payments)'
                    : implode(' | ', $allocationDetails);

                // Derive status from actual paid_amount (includes mirrors)
                // since shipment item status doesn't auto-sync from PI payments.
                $derivedStatus = $this->deriveDetailStatus($item, $itemPaid, $itemAmount);

                $rows[] = [
                    '_row_type' => 'detail',
                    'number' => '  ↳ '.($item->label ?? 'Installment'),
                    'bl_number' => optional($item->due_date)->format('d/m/Y') ?? '',
                    'client_reference' => $allocationsText,
                    'etd' => '',
                    'eta' => '',
                    'status' => $derivedStatus,
                    'freight' => '',
                    'other_costs' => '',
                    'total_costs' => round($itemAmount / Money::SCALE, 2),
                    'paid' => round($itemPaid / Money::SCALE, 2),
                    'balance' => round($itemRemaining / Money::SCALE, 2),
                    'currency' => (string) ($item->currency_code ?? ''),
                ];
            }
        }

        return new StatementSection(
            key: 'shipments',
            titleKey: 'financial_report.sections.shipments',
            columns: ['number', 'bl_number', 'client_reference', 'etd', 'eta', 'status', 'freight', 'other_costs', 'total_costs', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }

    /**
     * Get approved payment allocations from mirror PI/PO schedule items.
     * Shipment schedule items often mirror PI/PO items — users record
     * payments against the PI/PO, so we need to surface those here too.
     */
    private function getMirrorAllocations(PaymentScheduleItem $item): Collection
    {
        if (! $item->label) {
            return collect();
        }

        $docRef = $this->extractMirrorDocRef($item->label);
        if ($docRef === null) {
            return collect();
        }

        // Shipment-owned items have payable_id = shipment id; fall back to shipment_id
        $shipmentId = $item->payable_type === Shipment::class
            ? $item->payable_id
            : $item->shipment_id;

        if (! $shipmentId) {
            return collect();
        }

        $docClass = str_starts_with($docRef, 'PI')
            ? ProformaInvoice::class
            : PurchaseOrder::class;

        $docId = $docClass::where('reference', $docRef)->value('id');
        if (! $docId) {
            return collect();
        }

        $query = PaymentScheduleItem::where('payable_type', $docClass)
            ->where('payable_id', $docId)
            ->where('shipment_id', $shipmentId)
            ->where('id', '!=', $item->id);

        if ($item->payment_term_stage_id) {
            $query->where('payment_term_stage_id', $item->payment_term_stage_id);
        } else {
            $cleanLabel = trim(preg_replace('#\s*\x{2014}?\s*\[[^\]]+\]\s*$#u', '', (string) $item->label));
            if ($cleanLabel !== '') {
                $query->where('label', 'LIKE', $cleanLabel.'%');
            }
        }

        $mirrorIds = $query->pluck('id');

        if ($mirrorIds->isEmpty()) {
            return collect();
        }

        return PaymentAllocation::with('payment')
            ->whereIn('payment_schedule_item_id', $mirrorIds)
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->get();
    }

    private function extractMirrorDocRef(?string $label): ?string
    {
        if (! $label) {
            return null;
        }
        if (preg_match('#/\s*((?:PI|PO)-[\w-]+)\]#', $label, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Derive a display status for a schedule item based on actual paid amount
     * (which includes mirror allocations). The stored status doesn't auto-sync
     * when payments are recorded against the mirror PI/PO item.
     */
    private function deriveDetailStatus(PaymentScheduleItem $item, int $paid, int $amount): string
    {
        // Paid in full (100 minor unit tolerance for rounding)
        if ($amount > 0 && ($amount - $paid) <= 100) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partial';
        }

        if ($item->due_date && $item->due_date->isPast()) {
            return 'overdue';
        }

        return $item->status instanceof \BackedEnum
            ? $item->status->value
            : (string) $item->status;
    }
}
