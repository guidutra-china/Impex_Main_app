<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;

class RecalculateShipmentTotalsAction
{
    public function execute(Shipment $shipment): void
    {
        $this->syncCurrencyCode($shipment);

        $packingTotals = $shipment->packingListItems()
            ->selectRaw('SUM(total_gross_weight) as total_gross, SUM(total_net_weight) as total_net, SUM(total_volume) as total_vol, SUM(quantity) as total_pkgs')
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
            ->selectRaw('SUM(total_weight) as total_weight, SUM(total_volume) as total_volume')
            ->first();

        $shipment->update([
            'total_gross_weight' => $itemTotals->total_weight,
            'total_net_weight' => null,
            'total_volume' => $itemTotals->total_volume,
            'total_packages' => null,
        ]);
    }

    protected function syncCurrencyCode(Shipment $shipment): void
    {
        if ($shipment->currency_code) {
            return;
        }

        $firstItem = $shipment->items()
            ->with('proformaInvoiceItem.proformaInvoice')
            ->first();

        $currencyCode = $firstItem?->proformaInvoiceItem?->proformaInvoice?->currency_code;

        if ($currencyCode) {
            $shipment->updateQuietly(['currency_code' => $currencyCode]);
        }
    }
}
