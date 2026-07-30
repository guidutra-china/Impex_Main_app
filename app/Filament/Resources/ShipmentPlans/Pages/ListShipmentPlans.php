<?php

namespace App\Filament\Resources\ShipmentPlans\Pages;

use App\Filament\Concerns\HasQuickViewRecordAction;
use App\Filament\Resources\ShipmentPlans\ShipmentPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShipmentPlans extends ListRecords
{
    use HasQuickViewRecordAction;

    protected static string $resource = ShipmentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
