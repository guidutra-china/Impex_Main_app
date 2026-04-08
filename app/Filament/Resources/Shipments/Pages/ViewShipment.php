<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\Shipments\Concerns\ShipmentHeaderActions;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Resources\Shipments\Widgets\ShipmentSummary;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    use HasOperationsHeaderActions;
    use ShipmentHeaderActions;

    protected static string $resource = ShipmentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ShipmentSummary::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
