<?php

namespace App\Filament\Portal\Resources\QuotationResource\Pages;

use App\Filament\Portal\Resources\QuotationResource;
use App\Filament\Portal\Widgets\QuotationsListStats;
use Filament\Resources\Pages\ListRecords;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            QuotationsListStats::class,
        ];
    }
}
