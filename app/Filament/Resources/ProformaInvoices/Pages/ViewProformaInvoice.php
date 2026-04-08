<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Filament\Concerns\HasOperationsHeaderActions;
use App\Filament\Resources\ProformaInvoices\Concerns\ProformaInvoiceHeaderActions;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\ProformaInvoices\Widgets\ProformaInvoiceStats;
use App\Filament\Resources\ProformaInvoices\Widgets\ShipmentFulfillmentWidget;
use Filament\Resources\Pages\ViewRecord;

class ViewProformaInvoice extends ViewRecord
{
    use HasOperationsHeaderActions;
    use ProformaInvoiceHeaderActions;

    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ProformaInvoiceStats::class,
            ShipmentFulfillmentWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return $this->buildOperationsHeader();
    }
}
