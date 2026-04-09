<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

final class PurchaseOrderSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'purchase_orders';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = PurchaseOrder::query()
            ->where('supplier_company_id', $company->id)
            ->whereBetween('issue_date', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('issue_date');

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

        $rows = $query->with(['items', 'paymentScheduleItems.allocations.payment', 'paymentTerm'])
            ->get()
            ->map(function (PurchaseOrder $po) {
                $total = (int) $po->total;
                $paid = (int) $po->schedule_paid_total;

                return [
                    'number' => $po->reference ?? (string) $po->id,
                    'date' => optional($po->issue_date)->format('Y-m-d'),
                    'status' => $po->status instanceof \BackedEnum ? $po->status->value : (string) $po->status,
                    'incoterm' => $po->incoterm instanceof \BackedEnum ? $po->incoterm->value : (string) ($po->incoterm ?? ''),
                    'payment_term' => (string) ($po->paymentTerm?->name ?? ''),
                    'total' => round($total / Money::SCALE, 2),
                    'paid' => round($paid / Money::SCALE, 2),
                    'balance' => round(($total - $paid) / Money::SCALE, 2),
                    'currency' => (string) ($po->currency_code ?? ''),
                ];
            })
            ->all();

        return new StatementSection(
            key: 'purchase_orders',
            titleKey: 'statements.sections.purchase_orders',
            columns: ['number', 'date', 'status', 'incoterm', 'payment_term', 'total', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }
}
