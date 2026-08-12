<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\Quotations\Concerns\QuotationHeaderActions;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Resources\Pages\EditRecord;

class EditQuotation extends EditRecord
{
    use HasOperationsHeaderActions;
    use HasSaveAndReturnFormActions;
    use QuotationHeaderActions;

    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
