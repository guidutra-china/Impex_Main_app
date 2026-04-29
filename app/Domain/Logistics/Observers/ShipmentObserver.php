<?php

namespace App\Domain\Logistics\Observers;

use App\Domain\Logistics\Actions\SyncFulfillmentStatusAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;

class ShipmentObserver
{
    public function __construct(
        private readonly SyncFulfillmentStatusAction $sync,
    ) {}

    public function updated(Shipment $shipment): void
    {
        if (! $shipment->wasChanged('status')) {
            return;
        }

        $newStatus = $shipment->status;

        if ($newStatus instanceof ShipmentStatus
            && in_array($newStatus, [ShipmentStatus::IN_TRANSIT, ShipmentStatus::ARRIVED], true)) {
            $this->sync->execute($shipment);
        }
    }
}
