<?php

namespace App\Filament\Support;

use App\Domain\Financial\Models\DebitNote;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use App\Filament\Resources\ProformaInvoices\ProformaInvoiceResource;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Shipments\ShipmentResource;

/**
 * Resolves the admin-panel view URL for a payment schedule item's payable
 * document. Shared by the AR/AP open-item worklists and the dashboard tables.
 */
final class PayableResourceUrl
{
    public static function for(?object $payable): ?string
    {
        return match (true) {
            $payable instanceof ProformaInvoice => ProformaInvoiceResource::getUrl('view', ['record' => $payable]),
            $payable instanceof PurchaseOrder => PurchaseOrderResource::getUrl('view', ['record' => $payable]),
            $payable instanceof Shipment => ShipmentResource::getUrl('view', ['record' => $payable]),
            $payable instanceof DebitNote => DebitNoteResource::getUrl('view', ['record' => $payable]),
            default => null,
        };
    }
}
