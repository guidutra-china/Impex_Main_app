<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\PackingListItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;

class GeneratePackingListAction
{
    public function execute(Shipment $shipment): int
    {
        $shipment->packingListItems()->delete();

        $cartonCounter = 0;
        $sortOrder = 0;
        $created = 0;

        foreach ($shipment->items()->with('proformaInvoiceItem.product.packaging', 'proformaInvoiceItem.product.specification')->get() as $shipmentItem) {
            $created += $this->generateForItem($shipment, $shipmentItem, $cartonCounter, $sortOrder);
        }

        $this->updateShipmentTotals($shipment);

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

        $cartonWeight = (float) ($packaging->carton_weight ?? 0);
        $cartonLength = (float) ($packaging->carton_length ?? 0);
        $cartonWidth = (float) ($packaging->carton_width ?? 0);
        $cartonHeight = (float) ($packaging->carton_height ?? 0);
        $cartonCbm = (float) ($packaging->carton_cbm ?? 0);
        $netWeightPerPiece = $this->getNetWeightPerPiece($shipmentItem);

        if ($fullCartons > 0) {
            $cartonFrom = $cartonCounter + 1;
            $cartonTo = $cartonCounter + $fullCartons;
            $cartonCounter = $cartonTo;
            $sortOrder++;
            $created++;

            $netPerCarton = $netWeightPerPiece ? round($netWeightPerPiece * $pcsPerCarton, 3) : null;

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
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
            $partialNet = $netWeightPerPiece ? round($netWeightPerPiece * $remainder, 3) : null;
            $partialVolume = $cartonCbm ?: null;

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
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

        PackingListItem::create([
            'shipment_id' => $shipment->id,
            'shipment_item_id' => $shipmentItem->id,
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

    protected function getNetWeightPerPiece(ShipmentItem $shipmentItem): ?float
    {
        $spec = $shipmentItem->proformaInvoiceItem?->product?->specification;

        if ($spec && $spec->net_weight > 0) {
            return (float) $spec->net_weight;
        }

        return null;
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
