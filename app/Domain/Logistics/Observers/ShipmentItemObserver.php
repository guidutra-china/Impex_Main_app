<?php

namespace App\Domain\Logistics\Observers;

use App\Domain\Logistics\Actions\SyncFulfillmentStatusAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\ShipmentItem;

class ShipmentItemObserver
{
    public function __construct(
        private readonly SyncFulfillmentStatusAction $sync,
    ) {}

    public function created(ShipmentItem $item): void
    {
        $this->syncIfShipmentDispatched($item);
    }

    public function updated(ShipmentItem $item): void
    {
        $this->syncIfShipmentDispatched($item);
    }

    public function deleted(ShipmentItem $item): void
    {
        $this->syncIfShipmentDispatched($item);
    }

    private function syncIfShipmentDispatched(ShipmentItem $item): void
    {
        $shipment = $item->shipment;

        if ($shipment === null) {
            return;
        }

        $status = $shipment->status;

        if (! $status instanceof ShipmentStatus
            || ! in_array($status, [ShipmentStatus::IN_TRANSIT, ShipmentStatus::ARRIVED], true)) {
            return;
        }

        $this->sync->execute($shipment);
    }
}
