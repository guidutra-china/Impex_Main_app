<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Carton;
use Illuminate\Support\Facades\DB;

class DeleteCartonAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
        private readonly RenumberCartonsAction $renumber,
    ) {}

    public function execute(Carton $carton): void
    {
        $shipment = $carton->shipment;

        DB::transaction(function () use ($carton) {
            $carton->contents()->delete();
            $carton->delete();
        });

        if ($shipment->status === ShipmentStatus::DRAFT) {
            $this->renumber->execute($shipment);
        }

        $this->recalc->execute($shipment);
    }
}
