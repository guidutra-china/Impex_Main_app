<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\ShipmentPallet;

class UpdatePalletAction
{
    /**
     * Update mutable pallet fields. `id`, `shipment_id`, and `label` are ignored.
     */
    public function execute(ShipmentPallet $pallet, array $attributes): ShipmentPallet
    {
        unset($attributes['id'], $attributes['shipment_id'], $attributes['label']);

        $pallet->fill($attributes)->save();

        return $pallet->fresh();
    }
}
