<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Catalog\Models\Product;
use App\Domain\Logistics\Models\Shipment;

class PackingListPdfTemplate extends AbstractPdfTemplate
{
    private ?int $clientCompanyId = null;
    private array $clientPivotCache = [];

    public function getView(): string
    {
        return 'pdf.packing-list';
    }

    public function getDocumentTitle(): string
    {
        return $this->labels('packing_list');
    }

    public function getDocumentType(): string
    {
        return 'packing_list_pdf';
    }

    public function getFilename(): string
    {
        $reference = $this->model->reference ?? $this->model->getKey();

        return 'PL-' . $reference . '-v' . $this->getNextVersion() . '.pdf';
    }

    public function getOrientation(): string
    {
        return 'landscape';
    }

    protected function getDocumentData(): array
    {
        /** @var Shipment $shipment */
        $shipment = $this->model;
        $shipment->loadMissing([
            'company',
            'items.proformaInvoiceItem.product.companies',
            'items.proformaInvoiceItem.proformaInvoice',
            'packingListItems.shipmentItem.proformaInvoiceItem.product.companies',
        ]);

        $this->clientCompanyId = $shipment->company_id;
        $this->warmPivotCache($shipment);

        $currencyCode = $shipment->currency_code ?? 'USD';

        $piReferences = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->reference)
            ->filter()
            ->unique()
            ->implode(', ');

        $containerGroups = $this->buildContainerGroups($shipment);

        $totals = $this->calculateDedupedTotals($shipment->packingListItems);

        $documentDate = $shipment->issue_date ?? $shipment->etd ?? $shipment->created_at ?? now();

        return [
            'shipment' => [
                'reference' => $shipment->reference,
                'origin_port' => $shipment->origin_port,
                'destination_port' => $shipment->destination_port,
                'date' => $this->formatDate($documentDate),
                'etd' => $this->formatDate($shipment->etd),
                'bl_number' => $shipment->bl_number,
                'vessel_name' => $shipment->vessel_name,
                'currency_code' => $currencyCode,
                'pi_references' => $piReferences,
            ],
            'client' => [
                'name' => $shipment->company?->name ?? '—',
                'legal_name' => $shipment->company?->legal_name,
                'address' => $shipment->company?->full_address ?? '—',
                'phone' => $shipment->company?->phone,
                'email' => $shipment->company?->email,
                'tax_id' => $shipment->company?->tax_number,
            ],
            'container_groups' => $containerGroups,
            'has_multiple_containers' => count($containerGroups) > 1,
            'totals' => $totals,
        ];
    }

    private function buildContainerGroups(Shipment $shipment): array
    {
        $items = $shipment->packingListItems
            ->sortBy('carton_from')
            ->values();

        $grouped = $items->groupBy(fn ($item) => $item->container_number ?? '__none__');

        $containerGroups = [];

        foreach ($grouped as $containerNumber => $containerItems) {
            $lines = $this->buildMergedLines($containerItems);

            $containerTotals = $this->calculateDedupedTotals($containerItems);

            $containerGroups[] = [
                'container_number' => $containerNumber === '__none__' ? null : $containerNumber,
                'lines' => $lines,
                'totals' => $containerTotals,
            ];
        }

        return $containerGroups;
    }

    private function buildMergedLines(mixed $containerItems): array
    {
        $sorted = $containerItems->sortBy('carton_from')->values();

        $cartonGroups = $sorted->groupBy(fn ($item) => $item->carton_from . '-' . $item->carton_to);

        $lines = [];

        foreach ($cartonGroups as $cartonKey => $groupItems) {
            $isMixed = $groupItems->count() > 1;

            if ($isMixed) {
                $totalGW = $groupItems->sum(fn ($i) => (float) $i->total_gross_weight);
                $totalNW = $groupItems->sum(fn ($i) => (float) $i->total_net_weight);
                $totalVol = $groupItems->sum(fn ($i) => (float) $i->total_volume);
                $totalPkgs = $groupItems->first()->quantity;

                $firstItem = $groupItems->first();
                $firstProduct = $firstItem->shipmentItem?->proformaInvoiceItem?->product;
                $firstPivot = $this->getClientPivot($firstProduct);

                $lines[] = [
                    'package_no' => $this->formatPackageNumber($firstItem->carton_from, $firstItem->carton_to),
                    'pallet' => $firstItem->pallet_number ? 'PLT-' . str_pad($firstItem->pallet_number, 2, '0', STR_PAD_LEFT) : null,
                    'model_no' => $firstPivot?->external_code ?: ($firstProduct?->model_number ?: ($firstProduct?->sku ?? '')),
                    'product_name' => $firstPivot?->external_name ?: $firstItem->product_name,
                    'description' => $firstItem->description,
                    'unit' => $firstItem->shipmentItem?->unit ?? 'pcs',
                    'equipment_qty' => $firstItem->total_quantity,
                    'package_qty' => $totalPkgs,
                    'net_weight' => $totalNW ? number_format($totalNW, 1) : '',
                    'gross_weight' => $totalGW ? number_format($totalGW, 1) : '',
                    'dimensions' => $this->formatDimensions($firstItem),
                    'volume' => $totalVol ? number_format($totalVol, 2) : '',
                    'is_sub_item' => false,
                ];

                foreach ($groupItems->skip(1) as $subItem) {
                    $subProduct = $subItem->shipmentItem?->proformaInvoiceItem?->product;
                    $subPivot = $this->getClientPivot($subProduct);

                    $lines[] = [
                        'package_no' => '',
                        'pallet' => null,
                        'model_no' => $subPivot?->external_code ?: ($subProduct?->model_number ?: ($subProduct?->sku ?? '')),
                        'product_name' => $subPivot?->external_name ?: $subItem->product_name,
                        'description' => $subItem->description,
                        'unit' => $subItem->shipmentItem?->unit ?? 'pcs',
                        'equipment_qty' => $subItem->total_quantity,
                        'package_qty' => '',
                        'net_weight' => '',
                        'gross_weight' => '',
                        'dimensions' => '',
                        'volume' => '',
                        'is_sub_item' => true,
                    ];
                }
            } else {
                $item = $groupItems->first();
                $product = $item->shipmentItem?->proformaInvoiceItem?->product;
                $pivot = $this->getClientPivot($product);

                $lines[] = [
                    'package_no' => $this->formatPackageNumber($item->carton_from, $item->carton_to),
                    'pallet' => $item->pallet_number ? 'PLT-' . str_pad($item->pallet_number, 2, '0', STR_PAD_LEFT) : null,
                    'model_no' => $pivot?->external_code ?: ($product?->model_number ?: ($product?->sku ?? '')),
                    'product_name' => $pivot?->external_name ?: $item->product_name,
                    'description' => $item->description,
                    'unit' => $item->shipmentItem?->unit ?? 'pcs',
                    'equipment_qty' => $item->total_quantity,
                    'package_qty' => $item->quantity,
                    'net_weight' => $item->total_net_weight ? number_format((float) $item->total_net_weight, 1) : '',
                    'gross_weight' => $item->total_gross_weight ? number_format((float) $item->total_gross_weight, 1) : '',
                    'dimensions' => $this->formatDimensions($item),
                    'volume' => $item->total_volume ? number_format((float) $item->total_volume, 2) : '',
                    'is_sub_item' => false,
                ];
            }
        }

        return $lines;
    }

    private function formatPackageNumber(?int $from, ?int $to): string
    {
        if (! $from) {
            return '';
        }

        if (! $to || $from === $to) {
            return (string) $from;
        }

        return $from . '-' . $to;
    }

    private function formatDimensions($item): string
    {
        if (! $item->length && ! $item->width && ! $item->height) {
            return '';
        }

        $l = $item->length ? number_format((float) $item->length, 0) : '—';
        $w = $item->width ? number_format((float) $item->width, 0) : '—';
        $h = $item->height ? number_format((float) $item->height, 0) : '—';

        return "{$l} × {$w} × {$h}";
    }

    private function calculateDedupedTotals($items): array
    {
        $seenCartonRanges = [];
        $totalPackages = 0;
        $totalEquipmentQty = 0;
        $totalGrossWeight = 0;
        $totalNetWeight = 0;
        $totalVolume = 0;

        foreach ($items as $item) {
            $rangeKey = $item->carton_from . '-' . $item->carton_to;

            if (! isset($seenCartonRanges[$rangeKey])) {
                $seenCartonRanges[$rangeKey] = true;
                $totalPackages += (int) $item->quantity;
            }

            $totalEquipmentQty += (int) $item->total_quantity;
            $totalGrossWeight += (float) $item->total_gross_weight;
            $totalNetWeight += (float) $item->total_net_weight;
            $totalVolume += (float) $item->total_volume;
        }

        return [
            'total_packages' => $totalPackages,
            'total_gross_weight' => $totalGrossWeight,
            'total_net_weight' => $totalNetWeight,
            'total_volume' => $totalVolume,
            'total_equipment_qty' => $totalEquipmentQty,
            'packages' => $totalPackages,
            'equipment_qty' => $totalEquipmentQty,
            'gross_weight' => $totalGrossWeight,
            'net_weight' => $totalNetWeight,
            'volume' => $totalVolume,
        ];
    }

    private function warmPivotCache(Shipment $shipment): void
    {
        if (! $this->clientCompanyId) {
            return;
        }

        foreach ($shipment->items as $item) {
            $product = $item->proformaInvoiceItem?->product;
            if (! $product || isset($this->clientPivotCache[$product->id])) {
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

        foreach ($shipment->packingListItems as $plItem) {
            $product = $plItem->shipmentItem?->proformaInvoiceItem?->product;
            if (! $product || isset($this->clientPivotCache[$product->id])) {
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
