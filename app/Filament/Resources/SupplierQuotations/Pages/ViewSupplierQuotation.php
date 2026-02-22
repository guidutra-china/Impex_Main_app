<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierQuotation extends ViewRecord
{
    protected static string $resource = SupplierQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
