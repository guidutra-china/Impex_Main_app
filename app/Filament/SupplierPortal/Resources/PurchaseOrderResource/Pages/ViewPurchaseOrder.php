<?php

namespace App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Pages;

use App\Filament\SupplierPortal\Resources\PurchaseOrderResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PurchaseOrderResource\Widgets\SupplierPurchaseOrderStats::class,
        ];
    }
}
