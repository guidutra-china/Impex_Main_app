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
            'items.product',
            'additionalCosts.supplierCompany',
        ]);

        $currencyCode = $pi->currency_code ?? 'USD';

        $piItems = $pi->items->sortBy('sort_order')->values()->map(function ($item, $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'product_code' => $item->product?->sku ?? '—',
                'description' => $item->description ?? $item->product?->name ?? '—',
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'unit_price' => $this->formatMoney($item->unit_price, $currencyCode),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode, 2),
                'raw_line_total' => $item->line_total,
            ];
        });

        $piItemsTotal = $piItems->sum('raw_line_total');

        $clientCosts = $pi->additionalCosts
            ->where('billable_to', BillableTo::CLIENT)
            ->sortBy('cost_date')
            ->values();

        $costItems = $clientCosts->map(function ($cost, $index) use ($currencyCode) {
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

        $additionalCostsTotal = $clientCosts->sum('amount_in_document_currency');
        $paidTotal = $clientCosts->where('status.value', 'paid')->sum('amount_in_document_currency');
        $pendingAdditionalCosts = $additionalCostsTotal - $paidTotal;
        $grandTotal = $piItemsTotal + $additionalCostsTotal;

        return [
            'pi' => [
                'reference' => $pi->reference,
                'issue_date' => $this->formatDate($pi->issue_date),
                'currency_code' => $currencyCode,
            ],
            'client' => [
                'name' => $pi->company?->name ?? '—',
            ],
            'pi_items' => $piItems->map(fn ($item) => collect($item)->except('raw_line_total')->toArray())->toArray(),
            'pi_items_total' => $this->formatMoney($piItemsTotal, $currencyCode, 2),
            'items' => $costItems->toArray(),
            'totals' => [
                'additional_costs' => $this->formatMoney($additionalCostsTotal, $currencyCode, 2),
                'paid' => $this->formatMoney($paidTotal, $currencyCode, 2),
                'pending_additional' => $this->formatMoney($pendingAdditionalCosts, $currencyCode, 2),
                'has_pending' => $pendingAdditionalCosts > 0,
                'grand_total' => $this->formatMoney($grandTotal, $currencyCode, 2),
            ],
            'generated_at' => now()->format('d/m/Y H:i'),
        ];
    }
}
