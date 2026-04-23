<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Infrastructure\Support\Money;

final class DebitNoteSectionBuilder implements FinancialSectionBuilder
{
    public function key(): string
    {
        return 'debit_notes';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $query = DebitNote::query()
            ->where('company_id', $company->id)
            ->orderBy('issued_at');

        // Filter by date using issued_at
        $query->where(function ($q) use ($filters) {
            $q->whereBetween('issued_at', [$filters->from->toDateString(), $filters->to->toDateString()])
                ->orWhere(function ($q2) use ($filters) {
                    $q2->whereNull('issued_at')
                        ->whereBetween('created_at', [$filters->from, $filters->to]);
                });
        });

        if ($filters->currency !== null) {
            $query->where('currency_code', $filters->currency);
        }

        $rows = $query->with(['proformaInvoice', 'lineItems'])
            ->get()
            ->reject(fn (DebitNote $dn) => $filters->isExcluded('debit_notes', (int) $dn->id))
            ->map(function (DebitNote $dn) {
                $total = (int) $dn->total_amount;

                return [
                    '_row_type' => 'header',
                    '_entity_id' => (int) $dn->id,
                    'reference' => (string) ($dn->reference ?? ''),
                    'proforma_invoice' => (string) ($dn->proformaInvoice?->reference ?? ''),
                    'date' => optional($dn->issued_at)->format('Y-m-d'),
                    'due_date' => optional($dn->due_date)->format('Y-m-d'),
                    'total' => round($total / Money::SCALE, 2),
                    'status' => $dn->status instanceof \BackedEnum ? $dn->status->value : (string) $dn->status,
                    'currency' => (string) ($dn->currency_code ?? ''),
                ];
            })
            ->values()
            ->all();

        return new StatementSection(
            key: 'debit_notes',
            titleKey: 'financial_report.sections.debit_notes',
            columns: ['reference', 'proforma_invoice', 'date', 'due_date', 'total', 'status', 'currency'],
            rows: $rows,
        );
    }
}
