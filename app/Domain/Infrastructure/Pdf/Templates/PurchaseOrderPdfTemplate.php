<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\Infrastructure\Pdf\DocumentLabels;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

class PurchaseOrderPdfTemplate extends AbstractPdfTemplate
{
    protected bool $withImages;

    public function __construct(
        \Illuminate\Database\Eloquent\Model $model,
        string $locale = 'en',
        bool $withImages = false,
        array $options = [],
    ) {
        parent::__construct($model, $locale, $options);
        $this->withImages = $withImages;
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();
        $picSuffix = $this->withImages ? '-PIC' : '';

        return $reference.$picSuffix.'-v'.$this->getNextVersion().'.pdf';
    }

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
            'items.product.companies',
            'creator',
        ]);

        $currencyCode = $po->currency_code ?? 'USD';

        // Documento enviado ao fornecedor: identifica o produto como ELE o
        // conhece (código/nome/descrição do pivot), não pelo nosso SKU.
        // Fornecedor não tem conceito de filial — sem parent:.
        $identityResolver = ProductIdentityResolver::forSupplierCompany(
            company: $po->supplierCompany,
            overrides: $this->options,
        );
        $identityResolver->warm($po->items->map(fn ($item) => $item->product));

        $items = $po->items->sortBy('sort_order')->values()->map(function ($item, $index) use ($currencyCode, $identityResolver) {
            $identity = $identityResolver->resolve($item->product, lineDescription: $item->description);

            return [
                'index' => $index + 1,
                'product_code' => $identity->codeOr('—'),
                'description' => $this->formatDescription($identity->descriptionOr($identity->name) ?? ''),
                'specifications' => $item->specifications,
                'quantity' => $item->quantity,
                'unit' => $item->unit ?? 'pcs',
                'unit_cost' => $this->formatMoney($item->unit_cost, $currencyCode),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode, 2),
                'incoterm' => $item->incoterm instanceof \BackedEnum ? $item->incoterm->value : $item->incoterm,
                'image' => $this->withImages ? $this->resolveImagePath($item->product?->avatar) : null,
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
            'with_images' => $this->withImages,
            'totals' => [
                'grand_total' => $this->formatMoney($total, $currencyCode, 2),
            ],
            'payment_term' => [
                'name' => $po->paymentTerm?->name,
                'description' => $po->paymentTerm?->description,
            ],
            'po_terms' => $this->companySettings->po_terms,
        ];
    }
}
