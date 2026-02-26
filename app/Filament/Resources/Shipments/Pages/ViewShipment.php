<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Generate Packing List',
                )->name('generatePackingListPdf'),
                GeneratePdfAction::download(
                    documentType: 'packing_list_pdf',
                    label: 'Download Packing List',
                )->name('downloadPackingListPdf'),
                GeneratePdfAction::preview(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Preview Packing List',
                )->name('previewPackingListPdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'packing_list_pdf',
                    label: 'Send Packing List by Email',
                )->name('sendPackingListByEmail'),
            ])
                ->label('Packing List PDF')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('info'),

            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: CommercialInvoicePdfTemplate::class,
                    label: 'Generate Commercial Invoice',
                )->name('generateCommercialInvoicePdf'),
                GeneratePdfAction::download(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Download Commercial Invoice',
                )->name('downloadCommercialInvoicePdf'),
                GeneratePdfAction::preview(
                    templateClass: CommercialInvoicePdfTemplate::class,
                    label: 'Preview Commercial Invoice',
                )->name('previewCommercialInvoicePdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Send Commercial Invoice by Email',
                )->name('sendCommercialInvoiceByEmail'),
            ])
                ->label('Commercial Invoice PDF')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success'),

            EditAction::make(),
        ];
    }
}
