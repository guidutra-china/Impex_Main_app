<?php

namespace App\Filament\Resources\ShipmentPlans\Pages;

use App\Filament\Resources\ShipmentPlans\ShipmentPlanResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShipmentPlan extends CreateRecord
{
    protected static string $resource = ShipmentPlanResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
