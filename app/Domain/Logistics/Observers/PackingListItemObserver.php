<?php

namespace App\Domain\Logistics\Observers;

use App\Domain\Logistics\Actions\SyncCartonsFromLegacyAction;
use App\Domain\Logistics\Models\PackingListItem;

class PackingListItemObserver
{
    public function __construct(
        private readonly SyncCartonsFromLegacyAction $sync,
    ) {}

    public function saved(PackingListItem $item): void
    {
        $this->syncIfShipmentExists($item);
    }

    public function deleted(PackingListItem $item): void
    {
        $this->syncIfShipmentExists($item);
    }

    private function syncIfShipmentExists(PackingListItem $item): void
    {
        $shipment = $item->shipment;

        if ($shipment) {
            $this->sync->execute($shipment);
        }
    }
}
