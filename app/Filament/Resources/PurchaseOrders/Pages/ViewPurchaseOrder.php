<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Domain\Infrastructure\Pdf\Templates\PurchaseOrderPdfTemplate;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Actions\SendDocumentByEmailAction;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\PurchaseOrders\Widgets\POShipmentFulfillmentWidget;
use App\Filament\Resources\PurchaseOrders\Widgets\PurchaseOrderStats;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            PurchaseOrderStats::class,
            POShipmentFulfillmentWidget::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: PurchaseOrderPdfTemplate::class,
                label: 'Generate PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->helperText('If checked, each line item will display the product photo and the filename will include "PIC".'),
                ],
            ),
            GeneratePdfAction::download(
                documentType: 'purchase_order_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: PurchaseOrderPdfTemplate::class,
                label: 'Preview PDF',
                formSchema: [
                    Checkbox::make('with_images')
                        ->label('Include product photos')
                        ->live()
                        ->helperText('Preview the PDF with product photos in each line item.'),
                ],
            ),
            SendDocumentByEmailAction::make(
                documentType: 'purchase_order_pdf',
                label: 'Send by Email',
            ),
            EditAction::make(),
        ];
    }
}
