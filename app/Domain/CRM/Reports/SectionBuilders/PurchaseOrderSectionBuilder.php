<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

final class PurchaseOrderSectionBuilder implements SectionBuilder
{
    public function __construct(private readonly CompanyRole $role = CompanyRole::SUPPLIER) {}

    public function key(): string
    {
        return 'purchase_orders';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = PurchaseOrder::query()
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('issue_date');

        match ($this->role) {
            CompanyRole::CLIENT => $query->whereHas(
                'proformaInvoice',
                fn ($q) => $q->where('company_id', $company->id),
            ),
            default => $query->where('supplier_company_id', $company->id),
        };

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

        $rows = $query->with(['items', 'paymentScheduleItems.allocations.payment', 'paymentTerm', 'supplierCompany'])
            ->get()
            ->map(function (PurchaseOrder $po) {
                $total = (int) $po->total;
                $paid = (int) $po->schedule_paid_total;

                return [
                    'number' => $po->reference ?? (string) $po->id,
                    'supplier' => (string) ($po->supplierCompany?->name ?? ''),
                    'date' => optional($po->issue_date)->format('Y-m-d'),
                    'status' => $po->status instanceof \BackedEnum ? $po->status->value : (string) $po->status,
                    'incoterm' => $po->incoterm instanceof \BackedEnum ? $po->incoterm->value : (string) ($po->incoterm ?? ''),
                    'payment_term' => (string) ($po->paymentTerm?->name ?? ''),
                    'total' => round($total / Money::SCALE, 2),
                    'paid' => round($paid / Money::SCALE, 2),
                    'balance' => round(\App\Domain\CRM\Reports\DocumentBalance::open($po, $total, $paid) / Money::SCALE, 2),
                    'currency' => (string) ($po->currency_code ?? ''),
                ];
            })
            ->all();

        return new StatementSection(
            key: 'purchase_orders',
            titleKey: 'statements.sections.purchase_orders',
            columns: ['number', 'supplier', 'date', 'status', 'incoterm', 'payment_term', 'total', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }
}
