<?php

namespace App\Filament\Portal\Resources\ProformaInvoiceResource\Pages;

use App\Filament\Portal\Resources\ProformaInvoiceResource;
use App\Filament\Portal\Widgets\ProformaInvoicesListStats;
use Filament\Resources\Pages\ListRecords;

class ListProformaInvoices extends ListRecords
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProformaInvoicesListStats::class,
        ];
    }
}
