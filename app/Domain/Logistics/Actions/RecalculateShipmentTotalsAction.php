<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Services\PackingTotalsCalculator;

class RecalculateShipmentTotalsAction
{
    public function __construct(
        private readonly PackingTotalsCalculator $totals,
    ) {}

    public function execute(Shipment $shipment): void
    {
        $this->syncCurrencyCode($shipment);

        // total_packages são VOLUMES (bultos), não caixas: caixa fora de pallet
        // conta 1 e cada pallet conta 1, quantas caixas leve em cima. Peso e
        // cubagem de carga paletizada vêm do pallet, não das caixas.
        $totals = $this->totals->fromShipment($shipment);

        if ($totals['cartons'] > 0) {
            $shipment->update([
                'total_packages' => $totals['units'],
                'total_gross_weight' => $totals['gross'],
                'total_net_weight' => $totals['net'],
                'total_volume' => $totals['cbm'],
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
