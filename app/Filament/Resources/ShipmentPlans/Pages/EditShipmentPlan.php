<?php

namespace App\Filament\Resources\ShipmentPlans\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\ShipmentPlans\Concerns\ShipmentPlanHeaderActions;
use App\Filament\Resources\ShipmentPlans\ShipmentPlanResource;
use Filament\Resources\Pages\EditRecord;

class EditShipmentPlan extends EditRecord
{
    use HasOperationsHeaderActions;
    use ShipmentPlanHeaderActions;

    protected static string $resource = ShipmentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
