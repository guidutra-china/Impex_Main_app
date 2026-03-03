<?php

namespace App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Pages;

use App\Filament\SupplierPortal\Resources\PurchaseOrderResource;
use App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Widgets\SupplierPOShipmentFulfillmentWidget;
use App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Widgets\SupplierPurchaseOrderStats;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            SupplierPurchaseOrderStats::class,
            SupplierPOShipmentFulfillmentWidget::class,
        ];
    }
}
