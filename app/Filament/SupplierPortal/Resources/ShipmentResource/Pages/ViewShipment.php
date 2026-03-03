<?php

namespace App\Filament\SupplierPortal\Resources\ShipmentResource\Pages;

use App\Filament\SupplierPortal\Resources\ShipmentResource;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ShipmentResource\Widgets\SupplierShipmentStats::class,
        ];
    }
}
