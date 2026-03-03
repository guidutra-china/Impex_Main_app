<?php

namespace App\Filament\Resources\ProductionSchedules\Pages;

use App\Filament\Resources\ProductionSchedules\ProductionScheduleResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditProductionSchedule extends EditRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
