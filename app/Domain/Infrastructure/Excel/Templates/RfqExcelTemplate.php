<?php

namespace App\Domain\Infrastructure\Excel\Templates;

use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;

class RfqExcelTemplate extends AbstractExcelTemplate
{
    public function getDocumentTitle(): string
    {
        /** @var SupplierQuotation $sq */
        $sq = $this->model;

        $title = 'Request for Quotation — '.($sq->reference ?? '');
        if ($sq->company?->name) {
            $title .= ' — '.$sq->company->name;
        }

        return $title;
    }

    public function getDocumentType(): string
    {
        return 'rfq_excel';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return "RFQ-{$reference}.xlsx";
    }

    protected function getHeaders(): array
    {
        $headers = ['#', 'SKU', 'Model', 'Description', 'Specifications', 'Qty', 'Unit'];

        $showTargetPrice = $this->options['show_target_price'] ?? false;
        if ($showTargetPrice) {
            $headers[] = 'Target Price';
        }

        // Columns for supplier to fill
        $headers[] = 'Unit Cost';
        $headers[] = 'MOQ';
        $headers[] = 'Lead Time (days)';
        $headers[] = 'Notes';

        return $headers;
    }

    protected function getRows(): array
    {
        /** @var SupplierQuotation $sq */
        $sq = $this->model;

        $sq->loadMissing([
            'company',
            'items.product.companies',
            'items.inquiryItem',
        ]);

        $showTargetPrice = $this->options['show_target_price'] ?? false;
        $currencyCode = $sq->currency_code ?? 'USD';

        // Mesmo destinatário e mesma ausência de filial do RfqPdfTemplate.
        $identityResolver = ProductIdentityResolver::forSupplierCompany(
            company: $sq->company,
            overrides: $this->options,
        );
        $identityResolver->warm($sq->items->map(fn ($item) => $item->product));

        $rows = [];
        foreach ($sq->items as $index => $item) {
            $product = $item->product;
            $identity = $identityResolver->resolve(
                $product,
                lineName: $item->description,
                lineDescription: $item->description,
            );

            $row = [
                $index + 1,
                // Coluna SKU segue interna: é por ela que o fornecedor devolve
                // a planilha preenchida.
                $product?->sku ?? '—',
                $identity->codeOr(''),
                $identity->name,
                $identity->description ?: ($item->specifications ?? $product?->description ?? ''),
                $item->quantity ?? 0,
                $item->unit ?? 'pcs',
            ];

            if ($showTargetPrice) {
                $targetPrice = $item->inquiryItem?->target_price ?? 0;
                $row[] = $targetPrice > 0 ? Money::toMajor($targetPrice) : '';
            }

            // Empty columns for supplier to fill
            $row[] = ''; // Unit Cost
            $row[] = ''; // MOQ
            $row[] = ''; // Lead Time
            $row[] = $item->notes ?? '';

            $rows[] = $row;
        }

        return $rows;
    }
}
