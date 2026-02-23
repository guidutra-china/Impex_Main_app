<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Domain\Infrastructure\Pdf\Templates\PurchaseOrderPdfTemplate;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseOrder extends ViewRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: PurchaseOrderPdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'purchase_order_pdf',
                label: 'Download PDF',
            ),
            GeneratePdfAction::preview(
                templateClass: PurchaseOrderPdfTemplate::class,
                label: 'Preview PDF',
            ),
            EditAction::make(),
        ];
    }
}
