<?php

namespace App\Filament\Resources\SupplierQuotations\Pages;

use App\Domain\Infrastructure\Excel\Templates\RfqExcelTemplate;
use App\Domain\Infrastructure\Pdf\Templates\RfqPdfTemplate;
use App\Filament\Actions\GenerateExcelAction;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\SupplierQuotations\SupplierQuotationResource;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ViewRecord;

class ViewSupplierQuotation extends ViewRecord
{
    protected static string $resource = SupplierQuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: RfqPdfTemplate::class,
                label: 'Generate RFQ',
                icon: 'heroicon-o-document-arrow-down',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->default(false),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'rfq_pdf',
                label: 'Download RFQ',
            ),
            GeneratePdfAction::preview(
                templateClass: RfqPdfTemplate::class,
                label: 'Preview RFQ',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->helperText('Show the client\'s target price in the RFQ document')
                        ->default(false),
                ],
            ),
            GenerateExcelAction::download(
                templateClass: RfqExcelTemplate::class,
                label: 'Download RFQ Excel',
                formSchema: [
                    Toggle::make('show_target_price')
                        ->label('Include Target Price')
                        ->default(false),
                ],
            ),
            SendDocumentByEmailAction::make(
                documentType: 'rfq_pdf',
                label: 'Send by Email',
            ),
            EditAction::make(),
        ];
    }
}
