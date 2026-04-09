<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\CartonContent;

class DeleteCartonContentAction
{
    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalc,
    ) {}

    public function execute(CartonContent $content): void
    {
        $shipment = $content->carton->shipment;

        $content->delete();

        $this->recalc->execute($shipment);
    }
}
