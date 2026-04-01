<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;

class GeneratePackingListAction
{
    public function execute(Shipment $shipment, bool $mixed = false, array $mixedBoxConfig = []): int
    {
        $shipment->packingListItems()->delete();

        if ($mixed) {
            $count = $this->generateMixed($shipment, $mixedBoxConfig);
        } else {
            $count = $this->generateSeparate($shipment);
        }

        $this->updateShipmentTotals($shipment);

        return $count;
    }

    protected function generateSeparate(Shipment $shipment): int
    {
        $cartonCounter = 0;
        $sortOrder = 0;
        $created = 0;

        foreach ($shipment->items()->with('proformaInvoiceItem.product.packaging')->get() as $shipmentItem) {
            $created += $this->generateForItem($shipment, $shipmentItem, $cartonCounter, $sortOrder);
        }

        return $created;
    }

    /**
     * Generate packing list with mixed boxes — multiple products share the same carton range.
     *
     * Each product uses its own pcs_per_carton from packaging data to determine how many
     * pieces go into each box. The total number of boxes is the maximum needed across
     * all products. Weight/volume per box can be provided or left for manual edit.
     */
    protected function generateMixed(Shipment $shipment, array $config): int
    {
        $items = $shipment->items()->with('proformaInvoiceItem.product.packaging')->get();

        if ($items->isEmpty()) {
            return 0;
        }

        // Calculate how many boxes each product needs
        $itemData = [];
        $maxBoxes = 0;

        foreach ($items as $shipmentItem) {
            $packaging = $shipmentItem->proformaInvoiceItem?->product?->packaging;
            $totalQty = (int) $shipmentItem->quantity;

            if ($totalQty <= 0) {
                continue;
            }

            $pcsPerCarton = (int) ($packaging?->pcs_per_carton ?? 0);
            if ($pcsPerCarton <= 0) {
                $pcsPerCarton = $totalQty;
            }

            $boxesNeeded = (int) ceil($totalQty / $pcsPerCarton);

            if ($boxesNeeded > $maxBoxes) {
                $maxBoxes = $boxesNeeded;
            }

            $itemData[] = [
                'shipmentItem' => $shipmentItem,
                'packaging' => $packaging,
                'pcsPerCarton' => $pcsPerCarton,
                'totalQty' => $totalQty,
                'boxesNeeded' => $boxesNeeded,
            ];
        }

        if ($maxBoxes <= 0 || empty($itemData)) {
            return 0;
        }

        // Box-level overrides from config (optional)
        $boxGrossWeight = ! empty($config['gross_weight']) ? (float) $config['gross_weight'] : null;
        $boxNetWeight = ! empty($config['net_weight']) ? (float) $config['net_weight'] : null;
        $boxLength = ! empty($config['length']) ? (float) $config['length'] : null;
        $boxWidth = ! empty($config['width']) ? (float) $config['width'] : null;
        $boxHeight = ! empty($config['height']) ? (float) $config['height'] : null;
        $boxVolume = ! empty($config['volume']) ? (float) $config['volume'] : null;
        $rawType = $config['packaging_type'] ?? null;
        $packagingType = $rawType instanceof PackagingType
            ? $rawType
            : (PackagingType::tryFrom((string) ($rawType ?? '')) ?? PackagingType::CARTON);

        if (! $boxVolume && $boxLength && $boxWidth && $boxHeight) {
            $boxVolume = round(($boxLength * $boxWidth * $boxHeight) / 1_000_000, 4);
        }

        if (! $boxNetWeight && $boxGrossWeight) {
            $boxNetWeight = round($boxGrossWeight * 0.9, 3);
        }

        $cartonFrom = 1;
        $cartonTo = $maxBoxes;
        $sortOrder = 0;
        $created = 0;

        foreach ($itemData as $data) {
            $sortOrder++;
            $created++;

            $shipmentItem = $data['shipmentItem'];
            $totalQty = $data['totalQty'];
            $pcsPerCarton = $data['pcsPerCarton'];
            $packaging = $data['packaging'];

            // For weight distribution: if no box-level override, use product packaging
            // weight proportionally (weight per product in the mixed box)
            $itemGrossWeight = $boxGrossWeight;
            $itemNetWeight = $boxNetWeight;
            $itemLength = $boxLength;
            $itemWidth = $boxWidth;
            $itemHeight = $boxHeight;
            $itemVolume = $boxVolume;

            // If no box-level config, leave weights null so user can fill in later
            // Only the first item gets the box dimensions (others are sub-items in PDF)

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
                'packaging_type' => $packagingType,
                'carton_from' => $cartonFrom,
                'carton_to' => $cartonTo,
                'quantity' => $maxBoxes,
                'qty_per_carton' => $pcsPerCarton,
                'total_quantity' => $totalQty,
                'gross_weight' => $sortOrder === 1 ? $itemGrossWeight : null,
                'net_weight' => $sortOrder === 1 ? $itemNetWeight : null,
                'total_gross_weight' => $sortOrder === 1 && $itemGrossWeight
                    ? round($itemGrossWeight * $maxBoxes, 3) : null,
                'total_net_weight' => $sortOrder === 1 && $itemNetWeight
                    ? round($itemNetWeight * $maxBoxes, 3) : null,
                'length' => $sortOrder === 1 ? $itemLength : null,
                'width' => $sortOrder === 1 ? $itemWidth : null,
                'height' => $sortOrder === 1 ? $itemHeight : null,
                'volume' => $sortOrder === 1 ? $itemVolume : null,
                'total_volume' => $sortOrder === 1 && $itemVolume
                    ? round($itemVolume * $maxBoxes, 4) : null,
                'sort_order' => $sortOrder,
            ]);
        }

        return $created;
    }

    protected function generateForItem(Shipment $shipment, ShipmentItem $shipmentItem, int &$cartonCounter, int &$sortOrder): int
    {
        $packaging = $shipmentItem->proformaInvoiceItem?->product?->packaging;
        $pcsPerCarton = $packaging?->pcs_per_carton;

        if (! $pcsPerCarton || $pcsPerCarton <= 0) {
            return $this->createSingleLine($shipment, $shipmentItem, $cartonCounter, $sortOrder);
        }

        $totalQty = $shipmentItem->quantity;
        $fullCartons = intdiv($totalQty, $pcsPerCarton);
        $remainder = $totalQty % $pcsPerCarton;
        $created = 0;

        $packagingType = $packaging->packaging_type ?? PackagingType::CARTON;
        $cartonWeight = (float) ($packaging->carton_weight ?? 0);
        $cartonLength = (float) ($packaging->carton_length ?? 0);
        $cartonWidth = (float) ($packaging->carton_width ?? 0);
        $cartonHeight = (float) ($packaging->carton_height ?? 0);
        $cartonCbm = (float) ($packaging->carton_cbm ?? 0);

        if ($cartonCbm <= 0 && $cartonLength > 0 && $cartonWidth > 0 && $cartonHeight > 0) {
            $cartonCbm = round(($cartonLength * $cartonWidth * $cartonHeight) / 1_000_000, 4);
        }
        $cartonNetWeight = (float) ($packaging->carton_net_weight ?? 0);

        // Default net weight to 90% of gross weight if not set
        if ($cartonNetWeight <= 0 && $cartonWeight > 0) {
            $cartonNetWeight = round($cartonWeight * 0.9, 3);
        }

        if ($fullCartons > 0) {
            $cartonFrom = $cartonCounter + 1;
            $cartonTo = $cartonCounter + $fullCartons;
            $cartonCounter = $cartonTo;
            $sortOrder++;
            $created++;

            $netPerCarton = $cartonNetWeight ?: null;

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
                'packaging_type' => $packagingType,
                'carton_from' => $cartonFrom,
                'carton_to' => $cartonTo,
                'quantity' => $fullCartons,
                'qty_per_carton' => $pcsPerCarton,
                'total_quantity' => $fullCartons * $pcsPerCarton,
                'gross_weight' => $cartonWeight ?: null,
                'net_weight' => $netPerCarton,
                'total_gross_weight' => $cartonWeight ? round($cartonWeight * $fullCartons, 3) : null,
                'total_net_weight' => $netPerCarton ? round($netPerCarton * $fullCartons, 3) : null,
                'length' => $cartonLength ?: null,
                'width' => $cartonWidth ?: null,
                'height' => $cartonHeight ?: null,
                'volume' => $cartonCbm ?: null,
                'total_volume' => $cartonCbm ? round($cartonCbm * $fullCartons, 4) : null,
                'sort_order' => $sortOrder,
            ]);
        }

        if ($remainder > 0) {
            $cartonCounter++;
            $sortOrder++;
            $created++;

            $partialRatio = $remainder / $pcsPerCarton;
            $partialGross = $cartonWeight ? round($cartonWeight * $partialRatio, 3) : null;
            $partialNet = $cartonNetWeight ? round($cartonNetWeight * $partialRatio, 3) : null;
            $partialVolume = $cartonCbm ?: null;

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
                'packaging_type' => $packagingType,
                'carton_from' => $cartonCounter,
                'carton_to' => $cartonCounter,
                'quantity' => 1,
                'qty_per_carton' => $remainder,
                'total_quantity' => $remainder,
                'gross_weight' => $partialGross,
                'net_weight' => $partialNet,
                'total_gross_weight' => $partialGross,
                'total_net_weight' => $partialNet,
                'length' => $cartonLength ?: null,
                'width' => $cartonWidth ?: null,
                'height' => $cartonHeight ?: null,
                'volume' => $partialVolume,
                'total_volume' => $partialVolume,
                'sort_order' => $sortOrder,
            ]);
        }

        return $created;
    }

    protected function createSingleLine(Shipment $shipment, ShipmentItem $shipmentItem, int &$cartonCounter, int &$sortOrder): int
    {
        $cartonCounter++;
        $sortOrder++;

        $packaging = $shipmentItem->proformaInvoiceItem?->product?->packaging;
        $packagingType = $packaging?->packaging_type ?? PackagingType::CARTON;

        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
            'packaging_type' => $packagingType,
            'carton_from' => $cartonCounter,
            'carton_to' => $cartonCounter,
            'quantity' => 1,
            'qty_per_carton' => $shipmentItem->quantity,
            'total_quantity' => $shipmentItem->quantity,
            'gross_weight' => $shipmentItem->total_weight,
            'total_gross_weight' => $shipmentItem->total_weight,
            'total_volume' => $shipmentItem->total_volume,
            'sort_order' => $sortOrder,
        ]);

        return 1;
    }


    protected function updateShipmentTotals(Shipment $shipment): void
    {
        $totals = $shipment->packingListItems()
            ->selectRaw('SUM(total_gross_weight) as total_gross, SUM(total_net_weight) as total_net, SUM(total_volume) as total_vol, SUM(quantity) as total_pkgs')
            ->first();

        $shipment->update([
            'total_gross_weight' => $totals->total_gross,
            'total_net_weight' => $totals->total_net,
            'total_volume' => $totals->total_vol,
            'total_packages' => $totals->total_pkgs,
        ]);
    }
}
