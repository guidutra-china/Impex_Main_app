<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\CRM\Reports\StatusScopeFilter;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

final class ProformaInvoiceSectionBuilder implements SectionBuilder
{
    public function key(): string
    {
        return 'proforma_invoices';
    }

    public function build(Company $company, StatementFilters $filters): StatementSection
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

        $rows = $query->with(['items', 'paymentScheduleItems.allocations.payment'])
            ->get()
            ->map(function (ProformaInvoice $pi) {
                $total = (int) $pi->grand_total;
                $paid = (int) $pi->schedule_paid_total;

                return [
                    'number' => $pi->reference ?? (string) $pi->id,
                    'client_reference' => (string) ($pi->client_reference ?? ''),
                    'date' => optional($pi->issue_date)->format('Y-m-d'),
                    'status' => $pi->status instanceof \BackedEnum ? $pi->status->value : (string) $pi->status,
                    'total' => round($total / Money::SCALE, 2),
                    'paid' => round($paid / Money::SCALE, 2),
                    'balance' => round(($total - $paid) / Money::SCALE, 2),
                    'currency' => (string) ($pi->currency_code ?? ''),
                ];
            })
            ->all();

        return new StatementSection(
            key: 'proforma_invoices',
            titleKey: 'statements.sections.proforma_invoices',
            columns: ['number', 'client_reference', 'date', 'status', 'total', 'paid', 'balance', 'currency'],
            rows: $rows,
        );
    }
}
