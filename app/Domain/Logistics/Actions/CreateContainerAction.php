<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentContainer;
use Illuminate\Support\Facades\DB;

class CreateContainerAction
{
    /**
     * Create a new shipment container with an auto-generated CONT-NNN label.
     *
     * $attributes may set container_number, type (20GP/40HQ/etc.), seal_number,
     * notes. The label is always server-generated.
     */
    public function execute(Shipment $shipment, array $attributes = []): ShipmentContainer
    {
        return DB::transaction(function () use ($shipment, $attributes) {
            $label = $this->generateNextLabel($shipment);

            $attributes = array_merge([
                'sort_order' => $this->nextSortOrder($shipment),
            ], $attributes, [
                'shipment_id' => $shipment->id,
                'label' => $label,
            ]);

            return ShipmentContainer::create($attributes);
        });
    }

    private function generateNextLabel(Shipment $shipment): string
    {
        $labels = $shipment->shipmentContainers()->pluck('label');

        $max = 0;
        foreach ($labels as $label) {
            if (preg_match('/^CONT-(\d+)$/', (string) $label, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'CONT-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function nextSortOrder(Shipment $shipment): int
    {
        return (int) ($shipment->shipmentContainers()->max('sort_order') ?? 0) + 1;
    }
}
