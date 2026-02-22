<?php

namespace App\Filament\Resources\ProformaInvoices\Pages;

use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewProformaInvoice extends ViewRecord
{
    protected static string $resource = ProformaInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'proforma_invoice_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: ProformaInvoicePdfTemplate::class,
                label: 'Preview PDF',
            ),
            EditAction::make(),
        ];
    }
}
