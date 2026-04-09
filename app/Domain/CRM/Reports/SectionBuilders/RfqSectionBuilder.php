<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;

final class RfqSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'rfqs';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = SupplierQuotation::query()
            ->where('company_id', $company->id)
            ->whereBetween('requested_at', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('requested_at');

        $scope = StatusScopeFilter::whereClause($filters->statusScope);
        if ($scope !== null) {
            [$op, $values] = $scope;
            $op === 'in'
                ? $query->whereIn('status', $values)
                : $query->whereNotIn('status', $values);
        }

        $rows = $query->with('items')->get()->map(fn (SupplierQuotation $rfq) => [
            'number' => $rfq->reference ?? (string) $rfq->id,
            'date' => optional($rfq->requested_at)->format('Y-m-d'),
            'status' => $rfq->status instanceof \BackedEnum ? $rfq->status->value : (string) $rfq->status,
            'items' => $rfq->items->count(),
            'valid_until' => optional($rfq->valid_until)->format('Y-m-d'),
        ])->all();

        return new StatementSection(
            key: 'rfqs',
            titleKey: 'statements.sections.rfqs',
            columns: ['number', 'date', 'status', 'items', 'valid_until'],
            rows: $rows,
        );
    }
}
