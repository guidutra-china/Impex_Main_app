<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\Carton;
use Illuminate\Support\Facades\DB;

class DeleteCartonAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    public function execute(Carton $carton): void
    {
        $shipment = $carton->shipment;

        DB::transaction(function () use ($carton) {
            $carton->contents()->delete();
            $carton->delete();
        });

        $this->recalc->execute($shipment);
    }
}
