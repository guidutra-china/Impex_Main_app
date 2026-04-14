<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

final class MarginAnalysisSectionBuilder implements FinancialSectionBuilder
{
    public function key(): string
    {
        return 'margin_analysis';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $query = ProformaInvoice::query()
            ->where('company_id', $company->id)
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

        $rows = $query->with(['items', 'additionalCosts'])
            ->get()
            ->map(function (ProformaInvoice $pi) {
                $revenue = (int) $pi->total;
                $cost = (int) $pi->cost_total;

                $commission = (int) $pi->additionalCosts
                    ->where('cost_type', \App\Domain\Financial\Enums\AdditionalCostType::COMMISSION)
                    ->sum('amount_in_document_currency');

                $totalCost = $cost + $commission;
                $marginAmount = $revenue - $totalCost;
                $marginPct = $totalCost > 0 ? round(($marginAmount / $totalCost) * 100, 1) : 0;

                return [
                    'pi_reference' => $pi->reference ?? (string) $pi->id,
                    'revenue' => round($revenue / Money::SCALE, 2),
                    'cost' => round($cost / Money::SCALE, 2),
                    'commission' => round($commission / Money::SCALE, 2),
                    'margin_amount' => round($marginAmount / Money::SCALE, 2),
                    'margin_pct' => $marginPct . '%',
                    'currency' => (string) ($pi->currency_code ?? ''),
                ];
            })
            ->all();

        return new StatementSection(
            key: 'margin_analysis',
            titleKey: 'financial_report.sections.margin_analysis',
            columns: ['pi_reference', 'revenue', 'cost', 'commission', 'margin_amount', 'margin_pct', 'currency'],
            rows: $rows,
        );
    }
}
