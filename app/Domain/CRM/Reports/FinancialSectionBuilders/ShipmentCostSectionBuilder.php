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
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

final class ShipmentCostSectionBuilder implements FinancialSectionBuilder
{
    public function __construct(private readonly CompanyRole $role)
    {
    }

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
            $freight = $s->additionalCosts
                ->where('cost_type', AdditionalCostType::FREIGHT)
                ->where('billable_to', BillableTo::CLIENT)
                ->sum('amount_in_document_currency');

            $otherCosts = $s->additionalCosts
                ->where('cost_type', '!=', AdditionalCostType::FREIGHT)
                ->where('billable_to', BillableTo::CLIENT)
                ->sum('amount_in_document_currency');

            $totalCosts = $freight + $otherCosts;

            // Filter schedule items by direction:
            // - CLIENT report: exclude forwarder-payable items (those are outbound payments)
            // - SUPPLIER/forwarder report: include only forwarder-payable items
            $relevantScheduleItems = $s->paymentScheduleItems->filter(function ($item) use ($isClientReport) {
                $isForwarderPayable = str_contains((string) $item->notes, '[forwarder-payable]');

                return $isClientReport ? ! $isForwarderPayable : $isForwarderPayable;
            });

            $paidCosts = 0;
            foreach ($relevantScheduleItems as $item) {
                if ($item->is_credit) {
                    continue;
                }
                foreach ($item->allocations as $allocation) {
                    if ($allocation->payment && $allocation->payment->status === PaymentStatus::APPROVED) {
                        $paidCosts += (int) ($allocation->allocated_amount_in_document_currency ?? 0);
                    }
                }
            }

            // Shipment header row
            $rows[] = [
                '_row_type' => 'header',
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

            // Schedule item detail rows with allocation breakdown
            $scheduleItems = $relevantScheduleItems->sortBy('sort_order');

            foreach ($scheduleItems as $item) {
                if ($item->is_credit) {
                    continue;
                }

                $itemAmount = (int) $item->amount;
                $itemPaid = 0;
                $allocationDetails = [];

                foreach ($item->allocations as $allocation) {
                    if ($allocation->payment && $allocation->payment->status === PaymentStatus::APPROVED) {
                        $allocAmount = (int) ($allocation->allocated_amount_in_document_currency ?? 0);
                        $itemPaid += $allocAmount;
                        $allocationDetails[] = $allocation->payment->reference
                            .' ('.optional($allocation->payment->payment_date)->format('d/m/Y').')'
                            .': '.number_format($allocAmount / Money::SCALE, 2);
                    }
                }

                $itemRemaining = max(0, $itemAmount - $itemPaid);

                $allocationsText = empty($allocationDetails)
                    ? '(no payments)'
                    : implode(' | ', $allocationDetails);

                $rows[] = [
                    '_row_type' => 'detail',
                    'number' => '  ↳ '.($item->label ?? 'Installment'),
                    'bl_number' => optional($item->due_date)->format('d/m/Y') ?? '',
                    'client_reference' => $allocationsText,
                    'etd' => '',
                    'eta' => '',
                    'status' => $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status,
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
}
