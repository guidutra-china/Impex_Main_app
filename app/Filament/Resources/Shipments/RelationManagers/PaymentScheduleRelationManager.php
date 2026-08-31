<?php

namespace App\Filament\Resources\Shipments\RelationManagers;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Filament\RelationManagers\PaymentScheduleRelationManager as BasePaymentScheduleRelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentScheduleRelationManager extends BasePaymentScheduleRelationManager
{
    /**
     * Extend the base morphMany('payable') query so the table ALSO surfaces
     * the parcels of the documents this shipment carries:
     *
     * - PurchaseOrder parcels carved out for this shipment (supplier side);
     * - ProformaInvoice parcels carved out for this shipment (client side);
     * - ProformaInvoice document-level parcels (order date, before production,
     *   …) of the PIs in this shipment. Those stages are charged once per PI
     *   and never get a shipment-specific carve-out, so without this branch a
     *   shipment of a "100% in advance" PI shows only its additional costs and
     *   looks like the client owes nothing (SH-2026-00041 / PI-2026-00078).
     *
     * [remaining] rows — a shipment-dependent stage with no shipment link — are
     * the not-yet-shipped balance of the document and stay out, same rule as
     * ShipmentPaymentSummaryService.
     *
     * Using orWhere here preserves the morphMany base constraint
     * (payable_type=Shipment AND payable_id=ship.id) so existing items
     * — freight to forwarder, freight charged to client, etc. — keep showing.
     */
    public function table(Table $table): Table
    {
        $shipment = $this->getOwnerRecord();

        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->orWhere(
                fn (Builder $q) => $q
                    ->where('shipment_id', $shipment->id)
                    ->whereIn('payable_type', [PurchaseOrder::class, ProformaInvoice::class]),
            )->orWhere(
                fn (Builder $q) => $q
                    ->where('payable_type', ProformaInvoice::class)
                    ->whereNull('shipment_id')
                    ->whereIn('due_condition', CalculationBase::documentLevelValues())
                    ->whereIn('payable_id', $this->carriedProformaInvoiceIds($shipment)),
            ));
    }

    /**
     * Ids of the Proforma Invoices this shipment carries items from.
     *
     * @return array<int, int>
     */
    protected function carriedProformaInvoiceIds(Shipment $shipment): array
    {
        $shipment->loadMissing('items.proformaInvoiceItem');

        return $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proforma_invoice_id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
