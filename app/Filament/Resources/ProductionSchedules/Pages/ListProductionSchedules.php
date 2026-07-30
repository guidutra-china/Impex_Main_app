<?php

namespace App\Filament\Resources\ProductionSchedules\Pages;

use App\Filament\Concerns\HasQuickViewRecordAction;
use App\Filament\Resources\ProductionSchedules\ProductionScheduleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProductionSchedules extends ListRecords
{
    use HasQuickViewRecordAction;

    protected static string $resource = ProductionScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
