<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
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

        $rows = $query->with(['items.proformaInvoiceItem', 'additionalCosts'])->get()->map(function (Shipment $s) {
            $goodsValue = $s->items->sum(fn ($item) => $item->line_total);

            $freight = $s->additionalCosts
                ->where('cost_type', AdditionalCostType::FREIGHT)
                ->where('billable_to', BillableTo::CLIENT)
                ->sum('amount_in_document_currency');

            $duty = $s->additionalCosts
                ->where('cost_type', AdditionalCostType::CUSTOMS)
                ->where('billable_to', BillableTo::CLIENT)
                ->sum('amount_in_document_currency');

            $otherCosts = $s->additionalCosts
                ->whereNotIn('cost_type', [AdditionalCostType::FREIGHT, AdditionalCostType::CUSTOMS])
                ->where('billable_to', BillableTo::CLIENT)
                ->sum('amount_in_document_currency');

            $totalCosts = $freight + $duty + $otherCosts;

            // Check if costs have been paid via additional cost status
            $paidCosts = $s->additionalCosts
                ->where('billable_to', BillableTo::CLIENT)
                ->where('status', \App\Domain\Financial\Enums\AdditionalCostStatus::PAID)
                ->sum('amount_in_document_currency');

            return [
                'number' => $s->reference ?? (string) $s->id,
                'etd' => optional($s->etd)->format('Y-m-d'),
                'eta' => optional($s->eta)->format('Y-m-d'),
                'status' => $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status,
                'goods' => round($goodsValue / Money::SCALE, 2),
                'freight' => round($freight / Money::SCALE, 2),
                'customs' => round($duty / Money::SCALE, 2),
                'other_costs' => round($otherCosts / Money::SCALE, 2),
                'total_costs' => round($totalCosts / Money::SCALE, 2),
                'paid' => round($paidCosts / Money::SCALE, 2),
                'balance' => round(($totalCosts - $paidCosts) / Money::SCALE, 2),
                'currency' => (string) ($s->currency_code ?? ''),
            ];
        })->all();

        return new StatementSection(
            key: 'shipments',
            titleKey: 'financial_report.sections.shipments',
            columns: ['number', 'etd', 'eta', 'status', 'goods', 'freight', 'customs', 'other_costs', 'total_costs', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }
}
