<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\ShipmentContainer;
use Illuminate\Support\Facades\DB;

class DeleteContainerAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    /**
     * Delete a container. Child pallets and cartons are NOT deleted — they
     * become loose (shipment_container_id set to null via the FK's nullOnDelete
     * cascade), which matches the user's mental model: breaking up a container
     * should leave its contents intact, just unassigned.
     */
    public function execute(ShipmentContainer $container): void
    {
        $shipment = $container->shipment;

        DB::transaction(function () use ($container) {
            $container->delete();
        });

        $this->recalc->execute($shipment);
    }
}
