<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\SupplierQuotations\Concerns\SupplierQuotationHeaderActions;
use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierQuotation extends ViewRecord
{
    use HasOperationsHeaderActions;
    use SupplierQuotationHeaderActions;

    protected static string $resource = SupplierQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
