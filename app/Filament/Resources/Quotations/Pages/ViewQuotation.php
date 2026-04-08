<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\Quotations\Concerns\QuotationHeaderActions;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewQuotation extends ViewRecord
{
    use HasOperationsHeaderActions;
    use QuotationHeaderActions;

    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
