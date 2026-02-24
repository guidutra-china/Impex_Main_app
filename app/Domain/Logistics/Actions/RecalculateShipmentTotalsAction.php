<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;

class RecalculateShipmentTotalsAction
{
    public function execute(Shipment $shipment): void
    {
        $packingTotals = $shipment->packingListItems()
            ->selectRaw('SUM(gross_weight) as total_gross, SUM(net_weight) as total_net, SUM(volume) as total_vol, COUNT(*) as total_pkgs')
            ->first();

        if ($packingTotals && $packingTotals->total_pkgs > 0) {
            $shipment->update([
                'total_gross_weight' => $packingTotals->total_gross,
                'total_net_weight' => $packingTotals->total_net,
                'total_volume' => $packingTotals->total_vol,
                'total_packages' => $packingTotals->total_pkgs,
            ]);

            return;
        }

        $itemTotals = $shipment->items()
            ->selectRaw('SUM(total_weight) as total_weight, SUM(total_volume) as total_volume, COUNT(*) as item_count')
            ->first();

        $shipment->update([
            'total_gross_weight' => $itemTotals->total_weight,
            'total_net_weight' => null,
            'total_volume' => $itemTotals->total_volume,
            'total_packages' => null,
        ]);
    }
}
