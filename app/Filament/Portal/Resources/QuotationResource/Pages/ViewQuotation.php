<?php

namespace App\Filament\Portal\Resources\QuotationResource\Pages;

use App\Filament\Portal\Resources\QuotationResource;
use App\Filament\Portal\Resources\QuotationResource\Widgets\PortalQuotationSummary;
use Filament\Resources\Pages\ViewRecord;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PortalQuotationSummary::class,
        ];
    }
}
