<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Filament\Concerns\HasQuickViewRecordAction;
use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShipments extends ListRecords
{
    use HasQuickViewRecordAction;

    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
