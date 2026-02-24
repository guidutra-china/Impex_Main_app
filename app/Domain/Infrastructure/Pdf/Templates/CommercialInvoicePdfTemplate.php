<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Models\Product;
use App\Domain\Logistics\Models\Shipment;

class CommercialInvoicePdfTemplate extends AbstractPdfTemplate
{
    private ?int $clientCompanyId = null;
    private array $clientPivotCache = [];

    public function getView(): string
    {
        return 'pdf.commercial-invoice';
    }

    public function getDocumentTitle(): string
    {
        return $this->labels('commercial_invoice');
    }

    public function getDocumentType(): string
    {
        return 'commercial_invoice_pdf';
    }

    protected function getDocumentData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->model;
        $shipment->loadMissing([
            'company',
            'items.proformaInvoiceItem.product.companies',
            'items.proformaInvoiceItem.proformaInvoice.paymentTerm',
            'additionalCosts',
        ]);

        $this->clientCompanyId = $shipment->company_id;
        $this->warmPivotCache($shipment);

        $currencyCode = $shipment->currency_code ?? 'USD';

        $piReferences = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->reference)
            ->filter()
            ->unique()
            ->implode(', ');

        $paymentTerm = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->paymentTerm)
            ->filter()
            ->first();

        $incoterm = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->incoterm)
            ->filter()
            ->first();

        $items = $this->buildInvoiceItems($shipment, $currencyCode);
        $subtotal = $shipment->items->sum(fn ($item) => $item->line_total);

        $freightCosts = $shipment->additionalCosts
            ->filter(fn ($cost) => strtolower($cost->type ?? '') === 'freight')
            ->sum('amount');

        $grandTotal = $subtotal + $freightCosts;

        $documentDate = $shipment->issue_date ?? $shipment->etd ?? $shipment->created_at ?? now();

        return [
            'shipment' => [
                'reference' => $shipment->reference,
                'origin_port' => $shipment->origin_port,
                'destination_port' => $shipment->destination_port,
                'date' => $this->formatDate($documentDate),
                'etd' => $this->formatDate($shipment->etd),
                'currency_code' => $currencyCode,
                'pi_references' => $piReferences,
                'incoterm' => $incoterm instanceof \BackedEnum ? $incoterm->value : $incoterm,
            ],
            'client' => [
                'name' => $shipment->company?->name ?? '—',
                'legal_name' => $shipment->company?->legal_name,
                'address' => $shipment->company?->full_address ?? '—',
                'phone' => $shipment->company?->phone,
                'email' => $shipment->company?->email,
                'tax_id' => $shipment->company?->tax_number,
            ],
            'items' => $items,
            'totals' => [
                'subtotal' => $this->formatMoney($subtotal, $currencyCode),
                'freight' => $freightCosts > 0 ? $this->formatMoney($freightCosts, $currencyCode) : null,
                'grand_total' => $this->formatMoney($grandTotal, $currencyCode),
            ],
            'payment_term' => [
                'name' => $paymentTerm?->name,
                'description' => $paymentTerm?->description,
            ],
            'shipping_details' => $this->buildShippingDetails($shipment, $incoterm),
        ];
    }

    private function buildInvoiceItems(Shipment $shipment, string $currencyCode): array
    {
        return $shipment->items
            ->sortBy('sort_order')
            ->values()
            ->map(function ($item, $index) use ($currencyCode) {
                $product = $item->proformaInvoiceItem?->product;
                $piItem = $item->proformaInvoiceItem;
                $pivot = $this->getClientPivot($product);

                return [
                    'index' => $index + 1,
                    'model_no' => $pivot?->external_code ?: ($product?->model_number ?: ($product?->sku ?? '—')),
                    'product_name' => $pivot?->external_name ?: $item->product_name,
                    'description' => $pivot?->external_description ?: ($piItem?->description ?? $piItem?->specifications ?? ''),
                    'quantity' => $item->quantity,
                    'unit' => $piItem?->unit ?? 'pcs',
                    'unit_price' => $this->formatMoney($item->unit_price, $currencyCode),
                    'line_total' => $this->formatMoney($item->line_total, $currencyCode),
                ];
            })
            ->toArray();
    }

    private function buildShippingDetails(Shipment $shipment, $incoterm): array
    {
        $incotermStr = $incoterm instanceof \BackedEnum ? $incoterm->value : ($incoterm ?? '');
        $destination = $shipment->destination_port ?? '';

        return array_filter([
            'delivery_term' => $incotermStr && $destination
                ? "{$incotermStr} {$destination}"
                : ($incotermStr ?: null),
            'port_of_loading' => $shipment->origin_port,
            'port_of_destination' => $shipment->destination_port,
            'country_of_origin' => 'China',
        ]);
    }

    private function warmPivotCache(Shipment $shipment): void
    {
        if (! $this->clientCompanyId) {
            return;
        }

        foreach ($shipment->items as $item) {
            $product = $item->proformaInvoiceItem?->product;
            if (! $product) {
                continue;
            }

            $clientPivot = $product->companies
                ->where('pivot.company_id', $this->clientCompanyId)
                ->where('pivot.role', 'client')
                ->first();

            if ($clientPivot) {
                $this->clientPivotCache[$product->id] = $clientPivot->pivot;
            }
        }
    }

    private function getClientPivot(?Product $product)
    {
        if (! $product) {
            return null;
        }

        return $this->clientPivotCache[$product->id] ?? null;
    }

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
