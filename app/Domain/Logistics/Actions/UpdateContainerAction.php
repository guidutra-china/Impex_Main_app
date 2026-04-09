<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\ShipmentContainer;

class UpdateContainerAction
{
    /**
     * Update mutable container fields. `id`, `shipment_id`, and `label` in
     * $attributes are ignored (label is server-generated, never edited post-creation).
     */
    public function execute(ShipmentContainer $container, array $attributes): ShipmentContainer
    {
        unset($attributes['id'], $attributes['shipment_id'], $attributes['label']);

        $container->fill($attributes)->save();

        return $container->fresh();
    }
}
