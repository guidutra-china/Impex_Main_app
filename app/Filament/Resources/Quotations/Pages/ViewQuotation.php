<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewQuotation extends ViewRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: QuotationPdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'quotation_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: QuotationPdfTemplate::class,
                label: 'Preview PDF',
            ),
            SendDocumentByEmailAction::make(
                documentType: 'quotation_pdf',
                label: 'Send by Email',
            ),
            EditAction::make(),
        ];
    }
}
