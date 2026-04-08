<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\ProformaInvoices\Concerns\ProformaInvoiceHeaderActions;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Resources\Pages\EditRecord;

class EditProformaInvoice extends EditRecord
{
    use HasOperationsHeaderActions;
    use ProformaInvoiceHeaderActions;

    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
