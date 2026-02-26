<?php

namespace App\Filament\Portal\Resources\ShipmentResource\Pages;

use App\Filament\Portal\Resources\ShipmentResource;
use App\Filament\Portal\Resources\ShipmentResource\Widgets\PortalShipmentOverview;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PortalShipmentOverview::class,
        ];
    }
}
