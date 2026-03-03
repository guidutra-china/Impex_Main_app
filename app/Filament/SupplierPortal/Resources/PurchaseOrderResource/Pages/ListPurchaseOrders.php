<?php

namespace App\Filament\SupplierPortal\Resources\PurchaseOrderResource\Pages;

use App\Filament\SupplierPortal\Resources\PurchaseOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
