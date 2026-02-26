<?php

namespace App\Filament\Resources\Shipments\Pages;

use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\Shipments\ShipmentResource;
use App\Filament\Resources\Shipments\Widgets\LandedCostCalculator;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            LandedCostCalculator::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Generate PDF',
                )->name('generatePackingListPdf'),
                GeneratePdfAction::download(
                    documentType: 'packing_list_pdf',
                    label: 'Download PDF',
                )->name('downloadPackingListPdf'),
                GeneratePdfAction::preview(
                    templateClass: PackingListPdfTemplate::class,
                    label: 'Preview PDF',
                )->name('previewPackingListPdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'packing_list_pdf',
                    label: 'Send by Email',
                )->name('sendPackingListByEmail'),
            ])
                ->label(__('forms.labels.packing_list'))
                ->icon('heroicon-o-clipboard-document-list')
                ->color('info')
                ->button(),

            ActionGroup::make([
                GeneratePdfAction::make(
                    templateClass: CommercialInvoicePdfTemplate::class,
                    label: 'Generate PDF',
                )->name('generateCommercialInvoicePdf'),
                GeneratePdfAction::download(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Download PDF',
                )->name('downloadCommercialInvoicePdf'),
                GeneratePdfAction::preview(
                    templateClass: CommercialInvoicePdfTemplate::class,
                    label: 'Preview PDF',
                )->name('previewCommercialInvoicePdf'),
                SendDocumentByEmailAction::make(
                    documentType: 'commercial_invoice_pdf',
                    label: 'Send by Email',
                )->name('sendCommercialInvoiceByEmail'),
            ])
                ->label(__('forms.labels.commercial_invoice'))
                ->icon('heroicon-o-document-currency-dollar')
                ->color('success')
                ->button(),

            EditAction::make(),
        ];
    }
}
