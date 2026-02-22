<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSupplierQuotations extends ListRecords
{
    protected static string $resource = SupplierQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
