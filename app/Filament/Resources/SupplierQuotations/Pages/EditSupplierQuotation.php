<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\SupplierQuotations\Concerns\SupplierQuotationHeaderActions;
use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Resources\Pages\EditRecord;

class EditSupplierQuotation extends EditRecord
{
    use HasOperationsHeaderActions;
    use SupplierQuotationHeaderActions;

    protected static string $resource = SupplierQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
