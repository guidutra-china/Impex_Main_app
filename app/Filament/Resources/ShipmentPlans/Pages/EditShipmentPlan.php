<?php

namespace App\Filament\Resources\ShipmentPlans\Pages;

use App\Filament\Resources\ShipmentPlans\ShipmentPlanResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditShipmentPlan extends EditRecord
{
    protected static string $resource = ShipmentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
