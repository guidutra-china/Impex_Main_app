<?php

namespace App\Filament\SupplierPortal\Resources\ProductionScheduleResource\Pages;

use App\Filament\SupplierPortal\Resources\ProductionScheduleResource;
use Filament\Resources\Pages\ListRecords;

class ListProductionSchedules extends ListRecords
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
