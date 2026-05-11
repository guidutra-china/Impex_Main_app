<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

class CreateCartonAction
{
    /**
     * Create a new carton for a shipment with an auto-generated BOX-NNN label.
     *
     * $defaults may override container_number, pallet_number, packaging_type,
     * weights, dimensions, notes, sort_order. The `label` is always generated
     * server-side and any value provided in $defaults is ignored.
     */
    public function execute(Shipment $shipment, array $defaults = []): Carton
    {
        return DB::transaction(function () use ($shipment, $defaults) {
            $label = $this->generateNextLabel($shipment);

            $attributes = array_merge([
                'packaging_type' => PackagingType::CARTON->value,
                'sort_order' => $this->nextSortOrder($shipment),
            ], $defaults, [
                'shipment_id' => $shipment->id,
                'label' => $label, // always server-generated — wins over $defaults
            ]);

            return Carton::create($attributes);
        });
    }

    private function generateNextLabel(Shipment $shipment): string
    {
        $labels = $shipment->cartons()->pluck('label');

        $max = 0;
        foreach ($labels as $label) {
            if (preg_match('/^BOX-(\d+)$/', (string) $label, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'BOX-'.str_pad((string) ($max + 1), 3, '0', STR_PAD_LEFT);
    }

    private function nextSortOrder(Shipment $shipment): int
    {
        return (int) ($shipment->cartons()->max('sort_order') ?? 0) + 1;
    }
}
