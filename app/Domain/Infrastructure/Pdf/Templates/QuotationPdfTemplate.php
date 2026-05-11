<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Models\Quotation;

class QuotationPdfTemplate extends AbstractPdfTemplate
{
    protected bool $withImages;

    public function __construct(
        \Illuminate\Database\Eloquent\Model $model,
        string $locale = 'en',
        bool $withImages = true,
    ) {
        parent::__construct($model, $locale);
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
            'items.selectedSupplier',
            'items.suppliers.company',
            'creator',
        ]);

        $currencyCode = $quotation->currency_code ?? 'USD';
        $showSuppliers = (bool) $quotation->show_suppliers;

        $items = $quotation->items->map(function ($item, $index) use ($currencyCode, $showSuppliers) {
            $alternatives = $item->suppliers
                ->sortBy(fn ($alt) => (float) $alt->unit_cost * (float) ($alt->cost_exchange_rate ?? 1))
                ->values();

            $supplierNames = $alternatives->map(function ($alt, $idx) use ($showSuppliers) {
                return $showSuppliers
                    ? ($alt->company?->name ?? '—')
                    : 'Supplier '.($idx + 1);
            })->all();

            // Fallback for manual quotations created without supplier quotation sources.
            if (empty($supplierNames) && $item->selectedSupplier) {
                $supplierNames = [
                    $showSuppliers ? $item->selectedSupplier->name : 'Supplier 1',
                ];
            }

            return [
                'index' => $index + 1,
                'product_code' => $item->product?->sku ?? '—',
                'description' => $item->product?->name ?? $item->notes ?? '—',
                'quantity' => $item->quantity,
                'unit' => $item->product?->unit ?? 'pcs',
                'unit_price' => $this->formatMoney($item->unit_price, $currencyCode, 2),
                'line_total' => $this->formatMoney($item->line_total, $currencyCode, 2),
                'incoterm' => $item->incoterm instanceof \BackedEnum ? $item->incoterm->value : $item->incoterm,
                'suppliers' => $supplierNames,
                'image' => $this->withImages ? $this->resolveImagePath($item->product?->avatar) : null,
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
            'show_suppliers' => $showSuppliers,
            'has_suppliers' => $items->contains(fn ($i) => ! empty($i['suppliers'])),
            'with_images' => $this->withImages,
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
                'subtotal' => $this->formatMoney($quotation->subtotal, $currencyCode, 2),
                'show_commission' => $showCommission,
                'commission_rate' => $showCommission ? $quotation->commission_rate.'%' : null,
                'commission_amount' => $showCommission
                    ? $this->formatMoney($quotation->commission_amount, $currencyCode, 2)
                    : null,
                'grand_total' => $this->formatMoney($quotation->total, $currencyCode, 2),
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
