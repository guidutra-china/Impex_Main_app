<?php

namespace App\Domain\Financial\Queries;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
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
 * types; forwarder- and supplier-payable rows are excluded on the receivable
 * side via the side-tag scopes.
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
            ->withoutSideTags()
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
     * Notes, and supplier-payable cost rows (tag [supplier-payable]).
     */
    public static function payables(): Builder
    {
        $supplierDnIds = DebitNote::query()
            ->where('party_type', PartyType::SUPPLIER->value)
            ->where('status', DebitNoteStatus::ISSUED->value)
            ->pluck('id');

        return self::base()
            ->where(function ($q) use ($supplierDnIds) {
                $q->where('payable_type', PurchaseOrder::class);

                if ($supplierDnIds->isNotEmpty()) {
                    $q->orWhere(function ($q2) use ($supplierDnIds) {
                        $q2->where('payable_type', DebitNote::class)
                            ->whereIn('payable_id', $supplierDnIds);
                    });
                }

                $q->orWhere(function ($q2) {
                    $q2->where('source_type', AdditionalCost::class)
                        ->where('notes', 'LIKE', '%'.PaymentScheduleItem::SUPPLIER_PAYABLE_TAG.'%');
                });
            });
    }

    /**
     * Narrow an open-items query to a single counterparty. Mirrors
     * PaymentScheduleItem::counterpartyCompanyId(): on the payable side the
     * supplier named by a [supplier-payable] cost wins over the document
     * owner, which is why the cost branch is matched separately.
     */
    public static function filterByCounterparty(Builder $query, int $companyId, PaymentDirection $direction): Builder
    {
        if ($direction === PaymentDirection::INBOUND) {
            return $query->whereHasMorph(
                'payable',
                [ProformaInvoice::class, Shipment::class, DebitNote::class],
                fn ($q) => $q->where('company_id', $companyId),
            );
        }

        return $query->where(function (Builder $q) use ($companyId) {
            $q->whereHasMorph(
                'payable',
                [PurchaseOrder::class],
                fn ($pq) => $pq->where('supplier_company_id', $companyId),
            )
                ->orWhereHasMorph(
                    'payable',
                    [DebitNote::class],
                    fn ($pq) => $pq->where('company_id', $companyId),
                )
                ->orWhere(function (Builder $q2) use ($companyId) {
                    $q2->where('source_type', AdditionalCost::class)
                        ->whereIn(
                            'source_id',
                            AdditionalCost::query()
                                ->where('supplier_company_id', $companyId)
                                ->select('id'),
                        );
                });
        });
    }

    /**
     * Companies that can appear as counterparty on this side, for the filter
     * options.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    public static function counterpartyOptions(PaymentDirection $direction): \Illuminate\Support\Collection
    {
        return Company::withRole($direction === PaymentDirection::INBOUND ? CompanyRole::CLIENT : CompanyRole::SUPPLIER)
            ->orderBy('name')
            ->pluck('name', 'id');
    }

    private static function base(): Builder
    {
        return PaymentScheduleItem::query()
            ->where('is_credit', false)
            ->whereIn('status', self::openStatuses())
            // Documento pai cancelado → fora do balance, mesmo com parcela aberta.
            ->payableNotCancelled()
            ->with(['payable', 'source', 'shipment']);
    }
}
