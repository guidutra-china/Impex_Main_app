<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;

class SyncShipmentContainerNumbersAction
{
    /**
     * Mirror the container numbers filled on the packing list into the
     * shipment's `container_number` field (comma-separated, in packing order),
     * so the operator never has to retype them on the shipment form.
     *
     * When no packing-list container has a number, the field is left untouched
     * — a manually typed value must not be clobbered by an empty sync.
     */
    public function execute(Shipment $shipment): void
    {
        $numbers = $shipment->shipmentContainers()
            ->whereNotNull('container_number')
            ->where('container_number', '!=', '')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->pluck('container_number');

        if ($numbers->isEmpty()) {
            return;
        }

        $shipment->update([
            'container_number' => mb_substr($numbers->implode(', '), 0, 255),
        ]);
    }
}
