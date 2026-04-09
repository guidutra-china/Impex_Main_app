<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\ShipmentPallet;
use Illuminate\Support\Facades\DB;

class DeletePalletAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    /**
     * Delete a pallet. Child cartons become loose within the pallet's parent
     * container (if any) — we copy the container FK down to each carton before
     * deletion so they don't lose their container association.
     */
    public function execute(ShipmentPallet $pallet): void
    {
        $shipment = $pallet->shipment;

        DB::transaction(function () use ($pallet) {
            if ($pallet->shipment_container_id) {
                // Preserve container association on the now-detached cartons
                $pallet->cartons()->update([
                    'shipment_container_id' => $pallet->shipment_container_id,
                ]);
            }

            $pallet->delete();
        });

        $this->recalc->execute($shipment);
    }
}
