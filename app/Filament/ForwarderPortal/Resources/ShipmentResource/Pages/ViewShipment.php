<?php

namespace App\Filament\ForwarderPortal\Resources\ShipmentResource\Pages;

use App\Filament\ForwarderPortal\Resources\ShipmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn () => auth()->user()?->can('forwarder-portal:update-shipment-logistics') ?? false),
        ];
    }
}
