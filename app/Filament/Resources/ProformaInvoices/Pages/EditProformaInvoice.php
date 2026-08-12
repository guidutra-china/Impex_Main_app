<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\ProformaInvoices\Concerns\ProformaInvoiceHeaderActions;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Resources\Pages\EditRecord;

class EditProformaInvoice extends EditRecord
{
    use HasOperationsHeaderActions;
    use HasSaveAndReturnFormActions;
    use ProformaInvoiceHeaderActions;

    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
