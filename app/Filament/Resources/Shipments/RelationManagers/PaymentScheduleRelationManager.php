<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Filament\RelationManagers\PaymentScheduleRelationManager as BasePaymentScheduleRelationManager;

class PaymentScheduleRelationManager extends BasePaymentScheduleRelationManager
{
    /**
     * Override the morphMany('payable') default so the table also surfaces
     * PaymentScheduleItems linked to this shipment via PurchaseOrders
     * (payable_type=PurchaseOrder, shipment_id=ship.id) — supplier-side
     * parcels that are otherwise hidden from the Shipment view.
     */
    protected static string $relationship = 'shipmentRelatedPaymentScheduleItems';
}
