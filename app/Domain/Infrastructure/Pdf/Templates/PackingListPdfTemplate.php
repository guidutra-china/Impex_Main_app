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

        $totals = [
            'total_packages' => $shipment->packingListItems->sum('quantity'),
            'total_gross_weight' => $shipment->packingListItems->sum('total_gross_weight'),
            'total_net_weight' => $shipment->packingListItems->sum('total_net_weight'),
            'total_volume' => $shipment->packingListItems->sum('total_volume'),
            'total_equipment_qty' => $shipment->packingListItems->sum('total_quantity'),
        ];

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
        $items = $shipment->packingListItems->sortBy('sort_order')->values();

        $grouped = $items->groupBy(fn ($item) => $item->container_number ?? '__none__');

        $containerGroups = [];

        foreach ($grouped as $containerNumber => $containerItems) {
            $lines = [];
            $containerTotals = [
                'packages' => 0,
                'equipment_qty' => 0,
                'gross_weight' => 0,
                'net_weight' => 0,
                'volume' => 0,
            ];

            foreach ($containerItems as $item) {
                $product = $item->shipmentItem?->proformaInvoiceItem?->product;

                $packagePrefix = $this->getPackagePrefix($item->packaging_type);
                $packageNo = $this->formatPackageNumber($packagePrefix, $item->carton_from, $item->carton_to);

                $lines[] = [
                    'pallet' => $item->pallet_number ? 'PLT-' . str_pad($item->pallet_number, 2, '0', STR_PAD_LEFT) : null,
                    'package_no' => $packageNo,
                    'model_no' => $product?->sku ?? '',
                    'product_name' => $item->product_name,
                    'description' => $item->description,
                    'equipment_qty' => $item->total_quantity,
                    'package_qty' => $item->quantity,
                    'net_weight' => $item->total_net_weight ? number_format((float) $item->total_net_weight, 1) : '',
                    'gross_weight' => $item->total_gross_weight ? number_format((float) $item->total_gross_weight, 1) : '',
                    'dimensions' => $this->formatDimensions($item),
                    'volume' => $item->total_volume ? number_format((float) $item->total_volume, 2) : '',
                ];

                $containerTotals['packages'] += (int) $item->quantity;
                $containerTotals['equipment_qty'] += (int) $item->total_quantity;
                $containerTotals['gross_weight'] += (float) $item->total_gross_weight;
                $containerTotals['net_weight'] += (float) $item->total_net_weight;
                $containerTotals['volume'] += (float) $item->total_volume;
            }

            $containerGroups[] = [
                'container_number' => $containerNumber === '__none__' ? null : $containerNumber,
                'lines' => $lines,
                'totals' => $containerTotals,
            ];
        }

        return $containerGroups;
    }

    private function getPackagePrefix(?PackagingType $type): string
    {
        if (! $type) {
            return 'PKG';
        }

        return match ($type) {
            PackagingType::CARTON => 'CTN',
            PackagingType::BAG => 'BAG',
            PackagingType::DRUM => 'DRM',
            PackagingType::WOOD_BOX => 'WB',
            PackagingType::BULK => 'BLK',
        };
    }

    private function formatPackageNumber(string $prefix, ?int $from, ?int $to): string
    {
        if (! $from) {
            return '';
        }

        $fromStr = $prefix . str_pad($from, 2, '0', STR_PAD_LEFT);

        if (! $to || $from === $to) {
            return $fromStr;
        }

        $toStr = $prefix . str_pad($to, 2, '0', STR_PAD_LEFT);

        return $fromStr . '-' . $toStr;
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

    private function labels(string $key): string
    {
        return \App\Domain\Infrastructure\Pdf\DocumentLabels::get($key, $this->locale);
    }
}
