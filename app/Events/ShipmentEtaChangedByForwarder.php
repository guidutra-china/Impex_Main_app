<?php

namespace App\Events;

use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;

class ShipmentEtaChangedByForwarder
{
    use Dispatchable;

    public function __construct(
        public readonly Shipment $shipment,
        public readonly ?string $previousEta,
        public readonly ?string $newEta,
        public readonly ?int $userId,
    ) {}
}
