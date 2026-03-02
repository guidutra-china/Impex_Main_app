<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

class ProformaInvoicePdfTemplate extends AbstractPdfTemplate
{
    protected bool $hideCommission;

    public function __construct(\Illuminate\Database\Eloquent\Model $model, string $locale = 'en', bool $hideCommission = false)
    {
        parent::__construct($model, $locale);
        $this->hideCommission = $hideCommission;
    }

    public function getView(): string
    {
        return 'pdf.proforma-invoice';
    }

    public function getDocumentTitle(): string
    {
        return $this->labels('proforma_invoice');
    }

    public function getDocumentType(): string
    {
        return 'proforma_invoice_pdf';
    }

    protected function getDocumentData(): array
    {
        /** @var ProformaInvoice $pi */
        $pi = $this->model;
        $pi->loadMissing([
            'company',
            'contact',
            'inquiry',
            'paymentTerm',
            'quotations',
            'items.product',
            'items.supplierCompany',
            'additionalCosts',
            'creator',
        ]);

        $currencyCode = $pi->currency_code ?? 'USD';

        $items = $pi->items->sortBy('sort_order')->values()->map(function ($item, $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'product_code' => $item->product?->sku ?? '—',
                'description' => $item->description ?? $item->product?->name ?? '—',
                'specifications' => $item->specifications,
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'unit_price' => $this->formatMoney($item->unit_price, $currencyCode),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode, 2),
                'incoterm' => $item->incoterm instanceof \BackedEnum ? $item->incoterm->value : $item->incoterm,
            ];
        });

        $subtotal = $pi->items->sum(fn ($item) => $item->line_total);

        $serviceFees = [];
        if (! $this->hideCommission) {
            $serviceFees = $pi->additionalCosts
                ->where('cost_type', AdditionalCostType::COMMISSION)
                ->where('billable_to', BillableTo::CLIENT)
                ->map(fn ($cost) => [
                    'description' => $cost->description,
                    'amount' => $this->formatMoney($cost->amount_in_document_currency, $currencyCode, 2),
                    'raw_amount' => $cost->amount_in_document_currency,
                ])
                ->values()
                ->toArray();
        }

        $serviceFeeTotal = array_sum(array_column($serviceFees, 'raw_amount'));
        $grandTotal = $subtotal + $serviceFeeTotal;

        return [
            'proforma_invoice' => [
                'reference' => $pi->reference,
                'issue_date' => $this->formatDate($pi->issue_date),
                'valid_until' => $this->formatDate($pi->valid_until),
                'currency_code' => $currencyCode,
                'incoterm' => $pi->incoterm instanceof \BackedEnum ? $pi->incoterm->value : $pi->incoterm,
                'inquiry_reference' => $pi->inquiry?->reference,
                'notes' => $pi->notes,
                'created_by' => $pi->creator?->name,
                'linked_quotations' => $pi->quotations->pluck('reference')->implode(', '),
            ],
            'client' => [
                'name' => $pi->company?->name ?? '—',
                'legal_name' => $pi->company?->legal_name,
                'address' => $pi->company?->full_address ?? '—',
                'phone' => $pi->company?->phone,
                'email' => $pi->company?->email,
                'tax_id' => $pi->company?->tax_number,
                'contact_name' => $pi->contact?->name,
                'contact_email' => $pi->contact?->email,
            ],
            'items' => $items->toArray(),
            'service_fees' => $serviceFees,
            'totals' => [
                'subtotal' => $this->formatMoney($subtotal, $currencyCode, 2),
                'grand_total' => $this->formatMoney($grandTotal, $currencyCode, 2),
            ],
            'payment_term' => [
                'name' => $pi->paymentTerm?->name,
                'description' => $pi->paymentTerm?->description,
            ],
        ];
    }

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
