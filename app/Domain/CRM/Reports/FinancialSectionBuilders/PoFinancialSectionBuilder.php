<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

final class PoFinancialSectionBuilder implements FinancialSectionBuilder
{
    public function key(): string
    {
        return 'purchase_orders';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $query = PurchaseOrder::query()
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('issue_date');

        if ($filters->isSupplier()) {
            $query->where('supplier_company_id', $company->id);
        } elseif ($filters->isAdmin()) {
            // Admin context: the company being inspected may be a client,
            // a supplier, or both. Include POs where the company is the
            // supplier OR owns the linked proforma invoice, so admins see
            // the full set of POs related to this company either way.
            $query->where(function ($q) use ($company) {
                $q->where('supplier_company_id', $company->id)
                    ->orWhereHas(
                        'proformaInvoice',
                        fn ($sub) => $sub->where('company_id', $company->id),
                    );
            });
        } else {
            // Client portal: POs linked to this company's PIs
            $query->whereHas(
                'proformaInvoice',
                fn ($q) => $q->where('company_id', $company->id),
            );
        }

        $scope = StatusScopeFilter::whereClause($filters->statusScope);
        if ($scope !== null) {
            [$op, $values] = $scope;
            $op === 'in'
                ? $query->whereIn('status', $values)
                : $query->whereNotIn('status', $values);
        }

        if ($filters->currency !== null) {
            $query->where('currency_code', $filters->currency);
        }

        $pos = $query->with(['items', 'paymentScheduleItems.allocations.payment', 'paymentTerm', 'supplierCompany'])
            ->get();

        $rows = [];

        foreach ($pos as $po) {
            $total = (int) $po->total;
            $paid = (int) $po->schedule_paid_total;

            // PO header row
            $rows[] = [
                '_row_type' => 'header',
                'number' => $po->reference ?? (string) $po->id,
                'supplier' => (string) ($po->supplierCompany?->name ?? ''),
                'date' => optional($po->issue_date)->format('Y-m-d'),
                'status' => $po->status instanceof \BackedEnum ? $po->status->value : (string) $po->status,
                'payment_term' => (string) ($po->paymentTerm?->name ?? ''),
                'total' => round($total / Money::SCALE, 2),
                'paid' => round($paid / Money::SCALE, 2),
                'balance' => round(($total - $paid) / Money::SCALE, 2),
                'currency' => (string) ($po->currency_code ?? ''),
            ];

            // Payment schedule detail rows
            $scheduleItems = $po->paymentScheduleItems->sortBy('sort_order');

            foreach ($scheduleItems as $item) {
                if ($item->is_credit) {
                    continue;
                }

                $itemAmount = (int) $item->amount;
                $itemPaid = $this->computePaidAmount($item);
                $itemRemaining = max(0, $itemAmount - $itemPaid);

                $rows[] = [
                    '_row_type' => 'detail',
                    'number' => '',
                    'supplier' => '',
                    'date' => optional($item->due_date)->format('Y-m-d'),
                    'status' => $item->status instanceof \BackedEnum ? $item->status->value : (string) $item->status,
                    'payment_term' => '  ↳ ' . ($item->label ?? __('financial_report.columns.installment')),
                    'total' => round($itemAmount / Money::SCALE, 2),
                    'paid' => round($itemPaid / Money::SCALE, 2),
                    'balance' => round($itemRemaining / Money::SCALE, 2),
                    'currency' => '',
                ];
            }
        }

        return new StatementSection(
            key: 'purchase_orders',
            titleKey: 'financial_report.sections.purchase_orders',
            columns: ['number', 'supplier', 'date', 'status', 'payment_term', 'total', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }

    private function computePaidAmount(PaymentScheduleItem $item): int
    {
        $total = 0;
        foreach ($item->allocations as $allocation) {
            if ($allocation->payment && $allocation->payment->status === PaymentStatus::APPROVED) {
                $total += (int) ($allocation->allocated_amount_in_document_currency ?? 0);
            }
        }

        return $total;
    }
}
