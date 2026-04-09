<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentPallet;
use Illuminate\Support\Facades\DB;

class CreatePalletAction
{
    /**
     * Create a new pallet with auto-generated PLT-NNN label.
     *
     * Optionally nested inside a container via $attributes['shipment_container_id'].
     * $attributes may set length, width, height, notes.
     */
    public function execute(Shipment $shipment, array $attributes = []): ShipmentPallet
    {
        return DB::transaction(function () use ($shipment, $attributes) {
            $label = $this->generateNextLabel($shipment);

            $attributes = array_merge([
                'sort_order' => $this->nextSortOrder($shipment),
            ], $attributes, [
                'shipment_id' => $shipment->id,
                'label' => $label,
            ]);

            return ShipmentPallet::create($attributes);
        });
    }

    private function generateNextLabel(Shipment $shipment): string
    {
        $labels = $shipment->shipmentPallets()->pluck('label');

        $max = 0;
        foreach ($labels as $label) {
            if (preg_match('/^PLT-(\d+)$/', (string) $label, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'PLT-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function nextSortOrder(Shipment $shipment): int
    {
        return (int) ($shipment->shipmentPallets()->max('sort_order') ?? 0) + 1;
    }
}
