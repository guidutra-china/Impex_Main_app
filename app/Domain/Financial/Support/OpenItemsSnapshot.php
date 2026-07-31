<?php

namespace App\Domain\Financial\Support;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Per-request snapshot of the open AR/AP item universe for the financial
 * dashboard. Registered as a scoped singleton so the KPI, cash-flow, aging,
 * top-debtors and exposure widgets rendered in the same request all share ONE
 * load per direction instead of re-running the open-items query each.
 *
 * On top of memoizing, it batch-computes paid_amount with a single grouped
 * allocation query and injects it into each item, so reading remaining_amount
 * across the whole set costs zero extra queries (the live accessor fires two
 * per item).
 */
final class OpenItemsSnapshot
{
    /** @var Collection<int, PaymentScheduleItem>|null */
    private ?Collection $receivables = null;

    /** @var Collection<int, PaymentScheduleItem>|null */
    private ?Collection $payables = null;

    /** @return Collection<int, PaymentScheduleItem> */
    public function receivables(): Collection
    {
        return $this->receivables ??= $this->load(OpenScheduleItemsQuery::receivables());
    }

    /** @return Collection<int, PaymentScheduleItem> */
    public function payables(): Collection
    {
        return $this->payables ??= $this->load(OpenScheduleItemsQuery::payables());
    }

    /** @return Collection<int, PaymentScheduleItem> */
    private function load(Builder $query): Collection
    {
        $items = $query
            ->without(['payable', 'source', 'shipment'])
            ->with([
                // counterpartyName() reads payable->company (PI/Shipment/DN)
                // or payable->supplierCompany (PO), and source->supplierCompany
                // for OUTBOUND additional costs — eager load all of it.
                'payable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    ProformaInvoice::class => ['company'],
                    PurchaseOrder::class => ['supplierCompany'],
                    Shipment::class => ['company'],
                    DebitNote::class => ['company'],
                ]),
                'source' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                    AdditionalCost::class => ['supplierCompany'],
                ]),
            ])
            ->get();

        $this->prefillPaidAmounts($items);

        return $items;
    }

    /**
     * One grouped query replacing the two per-item allocation queries inside
     * PaymentScheduleItem::getPaidAmountAttribute(). Mirrors its semantics
     * exactly: every allocation row against the item (cash and credit
     * application alike) counts, but only when its payment is APPROVED.
     *
     * Shipment-owned items additionally fold in mirror allocations recorded
     * on the corresponding PI/PO items (getMirrorPaidAmount()). Those can
     * enter the open-items universe via AdditionalCost sources, so they are
     * deliberately left WITHOUT a precomputed value — their accessor runs the
     * full per-item math, preserving exact parity.
     *
     * @param  Collection<int, PaymentScheduleItem>  $items
     */
    private function prefillPaidAmounts(Collection $items): void
    {
        $eligible = $items->filter(
            fn (PaymentScheduleItem $item) => $item->payable_type !== Shipment::class
        );

        if ($eligible->isEmpty()) {
            return;
        }

        $sums = PaymentAllocation::query()
            ->whereIn('payment_schedule_item_id', $eligible->modelKeys())
            ->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::APPROVED))
            ->groupBy('payment_schedule_item_id')
            ->selectRaw('payment_schedule_item_id, SUM(allocated_amount_in_document_currency) AS total_paid')
            ->pluck('total_paid', 'payment_schedule_item_id');

        $eligible->each(
            fn (PaymentScheduleItem $item) => $item->setPrecomputedPaidAmount((int) ($sums[$item->id] ?? 0))
        );
    }
}
