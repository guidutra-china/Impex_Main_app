<?php

namespace App\Filament\Resources\ProductionSchedules\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\ProductionSchedules\Concerns\ProductionScheduleHeaderActions;
use App\Filament\Resources\ProductionSchedules\ProductionScheduleResource;
use Filament\Resources\Pages\EditRecord;

class EditProductionSchedule extends EditRecord
{
    use HasOperationsHeaderActions;
    use ProductionScheduleHeaderActions;

    protected static string $resource = ProductionScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
