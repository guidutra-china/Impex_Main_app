<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\PurchaseOrders\Concerns\PurchaseOrderHeaderActions;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrders\Widgets\POShipmentFulfillmentWidget;
use App\Filament\Resources\PurchaseOrders\Widgets\PurchaseOrderStats;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    use HasOperationsHeaderActions;
    use PurchaseOrderHeaderActions;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PurchaseOrderStats::class,
            POShipmentFulfillmentWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
