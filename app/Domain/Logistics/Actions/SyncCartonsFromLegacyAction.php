<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Facades\DB;

class SyncCartonsFromLegacyAction
{
    public function __construct(
        private readonly MigratePackingListItemsToCartonsAction $migrate,
    ) {}

    /**
     * Wipe cartons for this shipment and rebuild from the current packing_list_items.
     *
     * Used by PackingListItemObserver to keep the new carton tables in sync while
     * the legacy Filament UI remains the operator surface (PR #2 transition).
     *
     * Trade-off: O(n) per save — acceptable for typical shipments (10–100 cartons).
     * Optimization deferred until observed as a problem.
     */
    public function execute(Shipment $shipment): void
    {
        DB::transaction(function () use ($shipment) {
            $shipment->cartons()->each(function ($carton) {
                $carton->contents()->delete();
                $carton->delete();
            });

            $this->migrate->execute($shipment->fresh('packingListItems'));
        });
    }
}
