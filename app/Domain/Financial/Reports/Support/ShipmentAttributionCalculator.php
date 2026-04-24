<?php

namespace App\Domain\Financial\Reports\Support;

use App\Domain\Financial\Reports\DTOs\AttributionBasis;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

/**
 * Computes the share of a Shipment's cost that should be attributed to a
 * specific ProformaInvoice when the shipment carries items belonging to more
 * than one PI.
 *
 * Cascade (first non-zero denominator wins):
 *   1. weight   (sum ShipmentItem.total_weight)
 *   2. volume   (sum ShipmentItem.total_volume)
 *   3. quantity (sum ShipmentItem.quantity)
 *   4. value    (sum ShipmentItem.quantity * proformaInvoiceItem.unit_price)
 *
 * Both shipment and PI MUST be eager-loaded: `items` on each. Shipment items
 * also need `proforma_invoice_item_id`, `total_weight`, `total_volume`,
 * `quantity` (and `proformaInvoiceItem.unit_price` if value fallback triggers).
 */
final class ShipmentAttributionCalculator
{
    public function calculate(Shipment $shipment, ProformaInvoice $pi): ShipmentAttribution
    {
        $piItemIds = $pi->items->pluck('id')->all();
        $shipmentItems = $shipment->items;
        $piItems = $shipmentItems->whereIn('proforma_invoice_item_id', $piItemIds);

        $totalWeight = (float) $shipmentItems->sum('total_weight');
        if ($totalWeight > 0) {
            $piWeight = (float) $piItems->sum('total_weight');
            return new ShipmentAttribution($piWeight / $totalWeight, AttributionBasis::WEIGHT);
        }

        $totalVolume = (float) $shipmentItems->sum('total_volume');
        if ($totalVolume > 0) {
            $piVolume = (float) $piItems->sum('total_volume');
            return new ShipmentAttribution($piVolume / $totalVolume, AttributionBasis::VOLUME);
        }

        $totalQty = (int) $shipmentItems->sum('quantity');
        if ($totalQty > 0) {
            $piQty = (int) $piItems->sum('quantity');
            return new ShipmentAttribution($piQty / $totalQty, AttributionBasis::QUANTITY);
        }

        $totalValue = 0.0;
        $piValue = 0.0;
        foreach ($shipmentItems as $si) {
            $unitPrice = (float) ($si->proformaInvoiceItem?->unit_price ?? 0);
            $line = (float) $si->quantity * $unitPrice;
            $totalValue += $line;
            if (in_array($si->proforma_invoice_item_id, $piItemIds, true)) {
                $piValue += $line;
            }
        }
        if ($totalValue > 0) {
            return new ShipmentAttribution($piValue / $totalValue, AttributionBasis::VALUE);
        }

        return new ShipmentAttribution(0.0, AttributionBasis::WEIGHT);
    }
}

/**
 * Internal result record.
 */
final readonly class ShipmentAttribution
{
    public function __construct(
        public float $pct,
        public AttributionBasis $basis,
    ) {
    }
}
