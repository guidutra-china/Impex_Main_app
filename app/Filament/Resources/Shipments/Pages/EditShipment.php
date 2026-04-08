<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\Shipments\Concerns\ShipmentHeaderActions;
use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Resources\Pages\EditRecord;

class EditShipment extends EditRecord
{
    use HasOperationsHeaderActions;
    use ShipmentHeaderActions;

    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
