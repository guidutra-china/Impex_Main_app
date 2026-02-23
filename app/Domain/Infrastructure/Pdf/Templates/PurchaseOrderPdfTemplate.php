<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Infrastructure\Pdf\DocumentLabels;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

class PurchaseOrderPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.purchase-order';
    }

    public function getDocumentTitle(): string
    {
        return DocumentLabels::get('purchase_order', $this->locale);
    }

    public function getDocumentType(): string
    {
        return 'purchase_order_pdf';
    }

    protected function getDocumentData(): array
    {
        /** @var PurchaseOrder $po */
        $po = $this->model;
        $po->loadMissing([
            'supplierCompany',
            'contact',
            'proformaInvoice',
            'proformaInvoice.inquiry',
            'paymentTerm',
            'items.product',
            'creator',
        ]);

        $currencyCode = $po->currency_code ?? 'USD';

        $items = $po->items->sortBy('sort_order')->values()->map(function ($item, $index) use ($currencyCode) {
            return [
                'index' => $index + 1,
                'product_code' => $item->product?->sku ?? '—',
                'description' => $item->description ?? $item->product?->name ?? '—',
                'specifications' => $item->specifications,
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'unit_cost' => $this->formatMoney($item->unit_cost, $currencyCode),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode),
                'incoterm' => $item->incoterm instanceof \BackedEnum ? $item->incoterm->value : $item->incoterm,
            ];
        });

        $total = $po->items->sum(fn ($item) => $item->line_total);

        return [
            'purchase_order' => [
                'reference' => $po->reference,
                'issue_date' => $this->formatDate($po->issue_date),
                'expected_delivery_date' => $this->formatDate($po->expected_delivery_date),
                'currency_code' => $currencyCode,
                'incoterm' => $po->incoterm instanceof \BackedEnum ? $po->incoterm->value : $po->incoterm,
                'pi_reference' => $po->proformaInvoice?->reference,
                'inquiry_reference' => $po->proformaInvoice?->inquiry?->reference,
                'notes' => $po->notes,
                'shipping_instructions' => $po->shipping_instructions,
                'created_by' => $po->creator?->name,
            ],
            'supplier' => [
                'name' => $po->supplierCompany?->name ?? '—',
                'legal_name' => $po->supplierCompany?->legal_name,
                'address' => $po->supplierCompany?->full_address ?? '—',
                'phone' => $po->supplierCompany?->phone,
                'email' => $po->supplierCompany?->email,
                'tax_id' => $po->supplierCompany?->tax_number,
                'contact_name' => $po->contact?->name,
                'contact_email' => $po->contact?->email,
            ],
            'items' => $items->toArray(),
            'totals' => [
                'grand_total' => $this->formatMoney($total, $currencyCode),
            ],
            'payment_term' => [
                'name' => $po->paymentTerm?->name,
                'description' => $po->paymentTerm?->description,
            ],
        ];
    }
}
