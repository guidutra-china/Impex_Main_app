<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Infrastructure\Support\Money;

final class CreditNoteSectionBuilder implements FinancialSectionBuilder
{
    public function key(): string
    {
        return 'credit_notes';
    }

    public function build(Company $company, FinancialReportFilters $filters): StatementSection
    {
        $query = CreditNote::query()
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

        $rows = $query->with(['proformaInvoice', 'purchaseOrder', 'lineItems'])
            ->get()
            ->reject(fn (CreditNote $cn) => $filters->isExcluded('credit_notes', (int) $cn->id))
            ->map(function (CreditNote $cn) {
                $total = (int) $cn->total_amount;
                $remaining = $cn->remaining_amount;

                return [
                    '_row_type' => 'header',
                    '_entity_id' => (int) $cn->id,
                    'reference' => (string) ($cn->reference ?? ''),
                    'document' => (string) ($cn->proformaInvoice?->reference
                        ?? $cn->purchaseOrder?->reference
                        ?? ''),
                    'date' => optional($cn->issued_at)->format('Y-m-d'),
                    'total' => round($total / Money::SCALE, 2),
                    'remaining' => round($remaining / Money::SCALE, 2),
                    'status' => $cn->status instanceof \BackedEnum ? $cn->status->value : (string) $cn->status,
                    'currency' => (string) ($cn->currency_code ?? ''),
                ];
            })
            ->values()
            ->all();

        return new StatementSection(
            key: 'credit_notes',
            titleKey: 'financial_report.sections.credit_notes',
            columns: ['reference', 'document', 'date', 'total', 'remaining', 'status', 'currency'],
            rows: $rows,
        );
    }
}
