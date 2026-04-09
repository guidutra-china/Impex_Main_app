<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Inquiries\Models\Inquiry;

final class InquirySectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'inquiries';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
    {
        $query = Inquiry::query()
            ->where('company_id', $company->id)
            ->whereBetween('received_at', [$filters->from->toDateString(), $filters->to->toDateString()])
            ->orderBy('received_at');

        $scope = StatusScopeFilter::whereClause($filters->statusScope);
        if ($scope !== null) {
            [$op, $values] = $scope;
            $op === 'in'
                ? $query->whereIn('status', $values)
                : $query->whereNotIn('status', $values);
        }

        $rows = $query->with('items')->get()->map(fn (Inquiry $i) => [
            'number' => $i->reference ?? (string) $i->id,
            'date' => optional($i->received_at)->format('Y-m-d'),
            'status' => $i->status instanceof \BackedEnum ? $i->status->value : (string) $i->status,
            'items' => $i->items->count(),
        ])->all();

        return new StatementSection(
            key: 'inquiries',
            titleKey: 'statements.sections.inquiries',
            columns: ['number', 'date', 'status', 'items'],
            rows: $rows,
        );
    }
}
