<?php

namespace App\Filament\Portal\Resources\ShipmentResource\Pages;

use App\Filament\Portal\Resources\ShipmentResource;
use App\Filament\Portal\Widgets\ShipmentsListStats;
use Filament\Resources\Pages\ListRecords;

class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ShipmentsListStats::class,
        ];
    }
}
