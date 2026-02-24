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
        $created = 0;

        foreach ($shipment->items()->with('proformaInvoiceItem.product.packaging')->get() as $shipmentItem) {
            $created += $this->generateForItem($shipment, $shipmentItem, $cartonCounter);
        }

        $this->updateShipmentTotals($shipment);

        return $created;
    }

    protected function generateForItem(Shipment $shipment, ShipmentItem $shipmentItem, int &$cartonCounter): int
    {
        $packaging = $shipmentItem->proformaInvoiceItem?->product?->packaging;
        $pcsPerCarton = $packaging?->pcs_per_carton;

        if (! $pcsPerCarton || $pcsPerCarton <= 0) {
            $cartonCounter++;

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
                'carton_number' => $this->formatCartonNumber($cartonCounter),
                'quantity' => $shipmentItem->quantity,
                'gross_weight' => $shipmentItem->total_weight,
                'net_weight' => null,
                'length' => null,
                'width' => null,
                'height' => null,
                'volume' => $shipmentItem->total_volume,
                'sort_order' => $cartonCounter,
            ]);

            return 1;
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

        for ($i = 0; $i < $fullCartons; $i++) {
            $cartonCounter++;
            $created++;

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
                'carton_number' => $this->formatCartonNumber($cartonCounter),
                'quantity' => $pcsPerCarton,
                'gross_weight' => $cartonWeight ?: null,
                'net_weight' => $netWeightPerPiece ? round($netWeightPerPiece * $pcsPerCarton, 3) : null,
                'length' => $cartonLength ?: null,
                'width' => $cartonWidth ?: null,
                'height' => $cartonHeight ?: null,
                'volume' => $cartonCbm ?: null,
                'sort_order' => $cartonCounter,
            ]);
        }

        if ($remainder > 0) {
            $cartonCounter++;
            $created++;

            $partialWeightRatio = $remainder / $pcsPerCarton;

            PackingListItem::create([
                'shipment_id' => $shipment->id,
                'shipment_item_id' => $shipmentItem->id,
                'carton_number' => $this->formatCartonNumber($cartonCounter),
                'quantity' => $remainder,
                'gross_weight' => $cartonWeight ? round($cartonWeight * $partialWeightRatio, 3) : null,
                'net_weight' => $netWeightPerPiece ? round($netWeightPerPiece * $remainder, 3) : null,
                'length' => $cartonLength ?: null,
                'width' => $cartonWidth ?: null,
                'height' => $cartonHeight ?: null,
                'volume' => $cartonCbm ?: null,
                'sort_order' => $cartonCounter,
            ]);
        }

        return $created;
    }

    protected function getNetWeightPerPiece(ShipmentItem $shipmentItem): ?float
    {
        $spec = $shipmentItem->proformaInvoiceItem?->product?->specification;

        if ($spec && $spec->net_weight > 0) {
            return (float) $spec->net_weight;
        }

        return null;
    }

    protected function formatCartonNumber(int $number): string
    {
        return 'CTN-' . str_pad($number, 3, '0', STR_PAD_LEFT);
    }

    protected function updateShipmentTotals(Shipment $shipment): void
    {
        $totals = $shipment->packingListItems()
            ->selectRaw('SUM(gross_weight) as total_gross, SUM(net_weight) as total_net, SUM(volume) as total_vol, COUNT(*) as total_pkgs')
            ->first();

        $shipment->update([
            'total_gross_weight' => $totals->total_gross,
            'total_net_weight' => $totals->total_net,
            'total_volume' => $totals->total_vol,
            'total_packages' => $totals->total_pkgs,
        ]);
    }
}
