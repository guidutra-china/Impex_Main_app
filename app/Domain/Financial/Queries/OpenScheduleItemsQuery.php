<?php

namespace App\Domain\Financial\Queries;

use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Global (all-companies) open-item scopes for the admin AR/AP worklists.
 *
 * Mirrors the per-company predicates in
 * HasPaymentFormSections::getCompanyScheduleItems() but without a company
 * filter, so the admin can scan every open title at once. Returns an
 * Eloquent Builder (not a get()) so Filament tables can paginate/filter on it.
 *
 * "Open" = PENDING/DUE/OVERDUE and not a credit line. Shipment mirror rows are
 * excluded naturally because they never enter through these payable/source
 * types; forwarder-payable rows are excluded on the receivable side via notes.
 */
final class OpenScheduleItemsQuery
{
    /** @return array<int, string> */
    private static function openStatuses(): array
    {
        return [
            PaymentScheduleStatus::PENDING->value,
            PaymentScheduleStatus::DUE->value,
            PaymentScheduleStatus::OVERDUE->value,
        ];
    }

    /**
     * What clients owe Impex (receivables): PI installments, client Debit
     * Notes, and client-billable additional costs.
     */
    public static function receivables(): Builder
    {
        $clientDnIds = DebitNote::query()
            ->where('party_type', PartyType::CLIENT->value)
            ->where('status', DebitNoteStatus::ISSUED->value)
            ->pluck('id');

        $clientCostIds = AdditionalCost::query()
            ->where('billable_to', BillableTo::CLIENT)
            ->pluck('id');

        return self::base()
            ->where(function ($q) {
                $q->where('notes', 'NOT LIKE', '%[forwarder-payable]%')
                    ->orWhereNull('notes');
            })
            ->where(function ($q) use ($clientDnIds, $clientCostIds) {
                $q->where('payable_type', ProformaInvoice::class);

                if ($clientDnIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($clientDnIds) {
                        $q2->where('payable_type', DebitNote::class)
                            ->whereIn('payable_id', $clientDnIds);
                    });
                }

                if ($clientCostIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($clientCostIds) {
                        $q2->where('source_type', AdditionalCost::class)
                            ->whereIn('source_id', $clientCostIds);
                    });
                }
            });
    }

    /**
     * What Impex owes suppliers (payables): PO installments, supplier Debit
     * Notes, and supplier-billable additional costs.
     */
    public static function payables(): Builder
    {
        $supplierDnIds = DebitNote::query()
            ->where('party_type', PartyType::SUPPLIER->value)
            ->where('status', DebitNoteStatus::ISSUED->value)
            ->pluck('id');

        $supplierCostIds = AdditionalCost::query()
            ->whereNotNull('supplier_company_id')
            ->pluck('id');

        return self::base()
            ->where(function ($q) use ($supplierDnIds, $supplierCostIds) {
                $q->where('payable_type', PurchaseOrder::class);

                if ($supplierDnIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($supplierDnIds) {
                        $q2->where('payable_type', DebitNote::class)
                            ->whereIn('payable_id', $supplierDnIds);
                    });
                }

                if ($supplierCostIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($supplierCostIds) {
                        $q2->where('source_type', AdditionalCost::class)
                            ->whereIn('source_id', $supplierCostIds);
                    });
                }
            });
    }

    private static function base(): Builder
    {
        return PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->whereIn('status', self::openStatuses())
            ->with(['payable', 'source', 'shipment']);
    }
}
