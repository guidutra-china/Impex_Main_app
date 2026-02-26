<?php

namespace App\Filament\Portal\Resources\ProformaInvoiceResource\Pages;

use App\Filament\Portal\Resources\ProformaInvoiceResource;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalProformaInvoiceStats;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalShipmentFulfillmentWidget;
use Filament\Resources\Pages\ViewRecord;

class ViewProformaInvoice extends ViewRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PortalProformaInvoiceStats::class,
            PortalShipmentFulfillmentWidget::class,
        ];
    }
}
