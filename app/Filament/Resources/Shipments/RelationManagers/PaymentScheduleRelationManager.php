<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Filament\RelationManagers\PaymentScheduleRelationManager as BasePaymentScheduleRelationManager;
use Filament\Tables\Table;

class PaymentScheduleRelationManager extends BasePaymentScheduleRelationManager
{
    /**
     * Extend the base morphMany('payable') query so the table ALSO surfaces
     * PaymentScheduleItems linked to this shipment via PurchaseOrders
     * (payable_type=PurchaseOrder, shipment_id=ship.id) — supplier-side
     * parcels that are otherwise hidden from the Shipment view.
     *
     * Using orWhere here preserves the morphMany base constraint
     * (payable_type=Shipment AND payable_id=ship.id) so existing items
     * — freight to forwarder, freight charged to client, etc. — keep
     * showing as before; PO items are additive.
     */
    public function table(Table $table): Table
    {
        $shipment = $this->getOwnerRecord();

        return parent::table($table)
            ->modifyQueryUsing(fn ($query) => $query->orWhere(function ($q) use ($shipment) {
                $q->where('shipment_id', $shipment->id)
                    ->where('payable_type', PurchaseOrder::class);
            }));
    }
}
