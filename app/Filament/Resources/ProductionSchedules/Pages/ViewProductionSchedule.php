<?php

namespace App\Filament\Resources\ProductionSchedules\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\ProductionSchedules\Concerns\ProductionScheduleHeaderActions;
use App\Filament\Resources\ProductionSchedules\ProductionScheduleResource;
use App\Filament\Resources\ProductionSchedules\Widgets\ProductionGridWidget;
use Filament\Resources\Pages\ViewRecord;

class ViewProductionSchedule extends ViewRecord
{
    use HasOperationsHeaderActions;
    use ProductionScheduleHeaderActions;

    protected static string $resource = ProductionScheduleResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            ProductionGridWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
