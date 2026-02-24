<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\Shipment;

class PackingListPdfTemplate extends AbstractPdfTemplate
{
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
            'items.proformaInvoiceItem.product',
            'items.proformaInvoiceItem.proformaInvoice',
            'packingListItems.shipmentItem.proformaInvoiceItem.product',
        ]);

        $currencyCode = $shipment->currency_code ?? 'USD';

        $piReferences = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proformaInvoice?->reference)
            ->filter()
            ->unique()
            ->implode(', ');

        $containerGroups = $this->buildContainerGroups($shipment);

        $totals = $this->calculateDedupedTotals($shipment->packingListItems);

        return [
            'shipment' => [
                'reference' => $shipment->reference,
                'origin_port' => $shipment->origin_port,
                'destination_port' => $shipment->destination_port,
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

    /**
     * Merge items that share the same carton range (mixed cartons).
     * For mixed cartons: package_no, pkg_qty, NW, GW, dimensions, volume appear only on the first row.
     * Sub-items only show product info and equipment qty.
     */
    private function buildMergedLines(mixed $containerItems): array
    {
        $sorted = $containerItems->sortBy('carton_from')->values();

        // Group by carton_from + carton_to to detect mixed cartons
        $cartonGroups = $sorted->groupBy(fn ($item) => $item->carton_from . '-' . $item->carton_to);

        $lines = [];

        foreach ($cartonGroups as $cartonKey => $groupItems) {
            $isMixed = $groupItems->count() > 1;

            if ($isMixed) {
                // Sum weights and volume across all items in this carton group
                $totalGW = $groupItems->sum(fn ($i) => (float) $i->total_gross_weight);
                $totalNW = $groupItems->sum(fn ($i) => (float) $i->total_net_weight);
                $totalVol = $groupItems->sum(fn ($i) => (float) $i->total_volume);
                $totalPkgs = $groupItems->first()->quantity; // same carton = 1 package group

                // First item in the mixed carton: shows package info
                $firstItem = $groupItems->first();
                $firstProduct = $firstItem->shipmentItem?->proformaInvoiceItem?->product;

                $lines[] = [
                    'package_no' => $this->formatPackageNumber($firstItem->carton_from, $firstItem->carton_to),
                    'pallet' => $firstItem->pallet_number ? 'PLT-' . str_pad($firstItem->pallet_number, 2, '0', STR_PAD_LEFT) : null,
                    'model_no' => $firstProduct?->sku ?? '',
                    'product_name' => $firstItem->product_name,
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

                // Remaining items in the mixed carton: only product info
                foreach ($groupItems->skip(1) as $subItem) {
                    $subProduct = $subItem->shipmentItem?->proformaInvoiceItem?->product;

                    $lines[] = [
                        'package_no' => '',
                        'pallet' => null,
                        'model_no' => $subProduct?->sku ?? '',
                        'product_name' => $subItem->product_name,
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
                // Single product per carton range — normal row
                $item = $groupItems->first();
                $product = $item->shipmentItem?->proformaInvoiceItem?->product;

                $lines[] = [
                    'package_no' => $this->formatPackageNumber($item->carton_from, $item->carton_to),
                    'pallet' => $item->pallet_number ? 'PLT-' . str_pad($item->pallet_number, 2, '0', STR_PAD_LEFT) : null,
                    'model_no' => $product?->sku ?? '',
                    'product_name' => $item->product_name,
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

    /**
     * Calculate totals with deduplication for mixed cartons.
     * PKG QTY: count only once per unique carton range (carton_from-carton_to).
     * NW/GW/Volume/Equipment Qty: sum all rows (no dedup needed, values are per-product).
     */
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
            // Aliases for container subtotals
            'packages' => $totalPackages,
            'equipment_qty' => $totalEquipmentQty,
            'gross_weight' => $totalGrossWeight,
            'net_weight' => $totalNetWeight,
            'volume' => $totalVolume,
        ];
    }

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
