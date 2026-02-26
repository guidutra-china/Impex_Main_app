<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

class CostStatementPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.cost-statement';
    }

    public function getDocumentTitle(): string
    {
        return 'Cost Statement';
    }

    public function getDocumentType(): string
    {
        return 'cost_statement_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return "CS-{$reference}.pdf";
    }

    protected function getDocumentData(): array
    {
        /** @var ProformaInvoice $pi */
        $pi = $this->model;
        $pi->loadMissing([
            'company',
            'contact',
            'additionalCosts.supplierCompany',
        ]);

        $currencyCode = $pi->currency_code ?? 'USD';

        $clientCosts = $pi->additionalCosts
            ->where('billable_to', BillableTo::CLIENT)
            ->sortBy('cost_date')
            ->values();

        $items = $clientCosts->map(function ($cost, $index) use ($currencyCode) {
            $costTypeLabel = $cost->cost_type->getEnglishLabel();
            $isSameCurrency = $cost->currency_code === $currencyCode;

            return [
                'index' => $index + 1,
                'type' => $costTypeLabel,
                'description' => $cost->description,
                'original_amount' => $this->formatMoney($cost->amount, $cost->currency_code, 2),
                'original_currency' => $cost->currency_code,
                'document_amount' => $this->formatMoney($cost->amount_in_document_currency, $currencyCode, 2),
                'is_same_currency' => $isSameCurrency,
                'exchange_rate' => $cost->exchange_rate ? number_format((float) $cost->exchange_rate, 4) : null,
                'date' => $this->formatDate($cost->cost_date),
                'status' => $cost->status->getEnglishLabel(),
                'status_value' => $cost->status->value,
                'notes' => $cost->notes,
            ];
        });

        $totalInDocCurrency = $clientCosts->sum('amount_in_document_currency');
        $paidTotal = $clientCosts->where('status.value', 'paid')->sum('amount_in_document_currency');
        $pendingTotal = $totalInDocCurrency - $paidTotal;

        return [
            'pi' => [
                'reference' => $pi->reference,
                'issue_date' => $this->formatDate($pi->issue_date),
                'currency_code' => $currencyCode,
            ],
            'client' => [
                'name' => $pi->company?->name ?? '—',
            ],
            'items' => $items->toArray(),
            'totals' => [
                'total' => $this->formatMoney($totalInDocCurrency, $currencyCode, 2),
                'paid' => $this->formatMoney($paidTotal, $currencyCode, 2),
                'pending' => $this->formatMoney($pendingTotal, $currencyCode, 2),
                'has_pending' => $pendingTotal > 0,
            ],
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }
}
