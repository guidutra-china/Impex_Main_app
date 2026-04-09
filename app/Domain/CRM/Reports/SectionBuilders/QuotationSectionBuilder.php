<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Quotations\Models\Quotation;

final class QuotationSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'quotations';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = Quotation::query()
            ->where('company_id', $company->id)
            ->whereBetween('created_at', [$filters->from, $filters->to])
            ->orderBy('created_at');

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

        $rows = $query->with('items')->get()->map(fn (Quotation $q) => [
            'number' => $q->reference ?? (string) $q->id,
            'date' => optional($q->created_at)->format('Y-m-d'),
            'status' => $q->status instanceof \BackedEnum ? $q->status->value : (string) $q->status,
            'total' => round($q->total / Money::SCALE, 2),
            'currency' => (string) ($q->currency_code ?? ''),
            'valid_until' => optional($q->valid_until)->format('Y-m-d'),
        ])->all();

        return new StatementSection(
            key: 'quotations',
            titleKey: 'statements.sections.quotations',
            columns: ['number', 'date', 'status', 'total', 'currency', 'valid_until'],
            rows: $rows,
        );
    }
}
