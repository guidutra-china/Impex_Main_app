<?php

namespace App\Filament\Resources\ShipmentPlans\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\ShipmentPlans\Concerns\ShipmentPlanHeaderActions;
use App\Filament\Resources\ShipmentPlans\ShipmentPlanResource;
use App\Filament\Resources\ShipmentPlans\Widgets\ShipmentPlanSummaryWidget;
use Filament\Resources\Pages\ViewRecord;

class ViewShipmentPlan extends ViewRecord
{
    use HasOperationsHeaderActions;
    use ShipmentPlanHeaderActions;

    protected static string $resource = ShipmentPlanResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ShipmentPlanSummaryWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
