<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Models\Quotation;

class QuotationPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.quotation';
    }

    public function getDocumentTitle(): string
    {
        return $this->labels('quotation');
    }

    public function getDocumentType(): string
    {
        return 'quotation_pdf';
    }

    protected function getDocumentData(): array
    {
        /** @var Quotation $quotation */
        $quotation = $this->model;

        $quotation->loadMissing([
            'company',
            'contact',
            'inquiry',
            'paymentTerm',
            'items.product',
            'creator',
        ]);

        $currencyCode = $quotation->currency_code ?? 'USD';

        $items = $quotation->items->map(function ($item, $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'product_code' => $item->product?->sku ?? '—',
                'description' => $item->product?->name ?? $item->notes ?? '—',
                'quantity' => $item->quantity,
                'unit' => $item->product?->unit ?? 'pcs',
                'unit_price' => $this->formatMoney($item->unit_price, $currencyCode),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode),
                'incoterm' => $item->incoterm?->value,
            ];
        });

        $showCommission = $quotation->commission_type === CommissionType::SEPARATE
            && $quotation->commission_rate > 0;

        return [
            'quotation' => [
                'reference' => $quotation->reference,
                'date' => $this->formatDate($quotation->created_at),
                'valid_until' => $this->formatDate($quotation->valid_until),
                'currency_code' => $currencyCode,
                'version' => $quotation->version ?? 1,
                'inquiry_reference' => $quotation->inquiry?->reference,
                'notes' => $quotation->notes,
                'created_by' => $quotation->creator?->name,
            ],
            'client' => [
                'name' => $quotation->company?->name ?? '—',
                'legal_name' => $quotation->company?->legal_name,
                'address' => $quotation->company?->full_address ?? '—',
                'phone' => $quotation->company?->phone,
                'email' => $quotation->company?->email,
                'tax_id' => $quotation->company?->tax_number,
                'contact_name' => $quotation->contact?->name,
                'contact_email' => $quotation->contact?->email,
            ],
            'items' => $items->toArray(),
            'totals' => [
                'subtotal' => $this->formatMoney($quotation->subtotal, $currencyCode),
                'show_commission' => $showCommission,
                'commission_rate' => $showCommission ? $quotation->commission_rate . '%' : null,
                'commission_amount' => $showCommission
                    ? $this->formatMoney($quotation->commission_amount, $currencyCode)
                    : null,
                'grand_total' => $this->formatMoney($quotation->total, $currencyCode),
            ],
            'payment_term' => [
                'name' => $quotation->paymentTerm?->name,
                'description' => $quotation->paymentTerm?->description,
            ],
        ];
    }

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
