<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\Infrastructure\Pdf\DocumentLabels;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;

class RfqPdfTemplate extends AbstractPdfTemplate
{
    public function getView(): string
    {
        return 'pdf.rfq';
    }

    public function getDocumentTitle(): string
    {
        return DocumentLabels::get('request_for_quotation', $this->locale);
    }

    public function getDocumentType(): string
    {
        return 'rfq_pdf';
    }

    protected function getDocumentData(): array
    {
        /** @var SupplierQuotation $sq */
        $sq = $this->model;

        $sq->loadMissing([
            'company',
            'contact',
            'inquiry',
            'inquiry.items.product',
            'items.product.companies',
            'items.inquiryItem',
            'creator',
        ]);

        $currencyCode = $sq->currency_code ?? 'USD';
        $showTargetPrice = $this->options['show_target_price'] ?? false;

        // RFQ vai para o fornecedor: usa o código/nome que ele conhece.
        $identityResolver = ProductIdentityResolver::forSupplier($sq->company_id);
        $identityResolver->warm($sq->items->map(fn ($item) => $item->product));

        $items = $sq->items->map(function ($item, $index) use ($currencyCode, $showTargetPrice, $identityResolver) {
            $targetPrice = $item->inquiryItem?->target_price ?? 0;
            $quantity = $item->quantity ?? 0;

            $identity = $identityResolver->resolve(
                $item->product,
                lineName: $item->description,
                lineDescription: $item->description,
            );

            return [
                'index' => $index + 1,
                'product_code' => $identity->codeOr('—'),
                // Coluna MODEL segue sendo o modelo do fabricante — o código do
                // fornecedor já aparece em PRODUCT CODE, repetir não ajuda.
                'model_number' => $item->product?->model_number ?? '',
                'description' => $identity->name,
                'specifications' => $identity->description
                    ?: ($item->specifications ?? $item->product?->description ?? null),
                'quantity' => $quantity,
                'unit' => $item->unit ?? $item->product?->unit ?? 'pcs',
                'target_price' => $showTargetPrice && $targetPrice > 0 ? $this->formatMoney($targetPrice, $currencyCode) : null,
                'target_total' => $showTargetPrice && $targetPrice > 0 ? $this->formatMoney($targetPrice * $quantity, $currencyCode) : null,
                'notes' => $item->notes,
            ];
        });

        $totalTargetValue = $showTargetPrice
            ? $sq->items->sum(function ($item) {
                $targetPrice = $item->inquiryItem?->target_price ?? 0;

                return $targetPrice * ($item->quantity ?? 0);
            })
            : 0;

        $instructions = $sq->rfq_instructions
            ?? $this->companySettings->rfq_default_instructions
            ?? null;

        return [
            'rfq' => [
                'reference' => $sq->reference,
                'requested_date' => $this->formatDate($sq->requested_at),
                'response_deadline' => $this->formatDate($sq->inquiry?->deadline),
                'currency_code' => $currencyCode,
                'incoterm' => $sq->incoterm,
                'inquiry_reference' => $sq->inquiry?->reference,
                'notes' => $sq->notes,
                'created_by' => $sq->creator?->name,
            ],
            'supplier' => [
                'name' => $sq->company?->name ?? '—',
                'legal_name' => $sq->company?->legal_name,
                'address' => $sq->company?->full_address ?? '—',
                'phone' => $sq->company?->phone,
                'email' => $sq->company?->email,
                'contact_name' => $sq->contact?->name,
                'contact_email' => $sq->contact?->email,
            ],
            'items' => $items->toArray(),
            'total_target_value' => $totalTargetValue > 0
                ? $this->formatMoney($totalTargetValue, $currencyCode)
                : null,
            'instructions' => $instructions,
        ];
    }
}
