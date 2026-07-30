<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Concerns\HasQuickViewRecordAction;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuotations extends ListRecords
{
    use HasQuickViewRecordAction;

    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
