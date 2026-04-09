<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;

class RecalculateShipmentTotalsAction
{
    public function execute(Shipment $shipment): void
    {
        $this->syncCurrencyCode($shipment);

        $cartonTotals = $shipment->cartons()
            ->selectRaw('
                COUNT(*) as total_packages,
                COALESCE(SUM(gross_weight), 0) as total_gross,
                COALESCE(SUM(net_weight), 0) as total_net,
                COALESCE(SUM(volume), 0) as total_vol
            ')
            ->first();

        if ($cartonTotals && (int) $cartonTotals->total_packages > 0) {
            $shipment->update([
                'total_packages' => (int) $cartonTotals->total_packages,
                'total_gross_weight' => $cartonTotals->total_gross,
                'total_net_weight' => $cartonTotals->total_net,
                'total_volume' => $cartonTotals->total_vol,
            ]);

            return;
        }

        // Fallback: no cartons → use shipment_items totals (preserves current behavior).
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
