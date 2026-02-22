<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSupplierQuotation extends CreateRecord
{
    protected static string $resource = SupplierQuotationResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
