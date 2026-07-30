<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Concerns\HasQuickViewRecordAction;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseOrders extends ListRecords
{
    use HasQuickViewRecordAction;

    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
