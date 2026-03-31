<?php

namespace App\Filament\SupplierPortal\Resources\ProductionScheduleResource\Pages;

use App\Filament\SupplierPortal\Resources\ProductionScheduleResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProductionSchedule extends ViewRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getRelationManagers(): array
    {
        return []; // Replaced by Livewire components in infolist
    }
}
