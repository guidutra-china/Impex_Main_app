<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;

/**
 * Single source of truth for propagating settlement state after anything
 * that changes effective paid amounts: allocation created/deleted, payment
 * approved/cancelled.
 *
 * Propagation order matters: schedule item statuses are recalculated first,
 * then document-level state (DebitNote, AdditionalCost) is derived from the
 * fresh statuses, then shipment mirrors are synced.
 */
class ReconcileSettlementStateAction
{
    /**
     * Reconcile every schedule item touched by the payment's allocations.
     * Used when payment approval state changes (approve/reject/cancel),
     * because paid_amount accessors only count APPROVED payments — so the
     * document-level state computed at allocation time is stale by now.
     */
    public function forPayment(Payment $payment): void
    {
        $allocations = $payment->allocations()->with('scheduleItem')->get();

        foreach ($allocations as $allocation) {
            $this->forAllocation($allocation);
        }
    }

    public function forAllocation(PaymentAllocation $allocation): void
    {
        $this->recalculateScheduleItemStatus($allocation);
        $this->checkDebitNoteReconciliation($allocation);
        $this->checkCreditNoteReconciliation($allocation);
        $this->syncAdditionalCostStatus($allocation);

        if ($allocation->scheduleItem) {
            $this->syncShipmentMirrorStatus($allocation->scheduleItem);
        }
    }

    /**
     * Keep the schedule item's stored status in sync with its live
     * paid_amount. Without this, removing an allocation (for example by
     * editing a payment or cancelling it) leaves the item stuck at PAID
     * even after paid_amount falls back to 0.
     */
    protected function recalculateScheduleItemStatus(PaymentAllocation $allocation): void
    {
        $scheduleItem = $allocation->scheduleItem;

        if ($scheduleItem) {
            $scheduleItem->recalculateStatus();
        }

        if ($allocation->credit_schedule_item_id) {
            $creditItem = PaymentScheduleItem::find($allocation->credit_schedule_item_id);
            $creditItem?->recalculateStatus();
        }
    }

    /**
     * Check if all schedule items sourced from a DebitNote's line items are paid.
     * If so, transition the DebitNote to PAID. If partially paid, set PARTIALLY_PAID.
     */
    protected function checkDebitNoteReconciliation(PaymentAllocation $allocation): void
    {
        $scheduleItem = $allocation->scheduleItem;

        if (! $scheduleItem) {
            return;
        }

        // Only process schedule items sourced from DebitNoteLineItem
        if ($scheduleItem->source_type !== DebitNoteLineItem::class) {
            return;
        }

        $lineItem = DebitNoteLineItem::find($scheduleItem->source_id);

        if (! $lineItem) {
            return;
        }

        $debitNote = $lineItem->debitNote;

        if (! $debitNote || $debitNote->status === DebitNoteStatus::CANCELLED) {
            return;
        }

        // Get all schedule items for this debit note
        $lineItemIds = $debitNote->lineItems()->pluck('id');

        $scheduleItems = PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
            ->whereIn('source_id', $lineItemIds)
            ->get();

        if ($scheduleItems->isEmpty()) {
            return;
        }

        $allPaid = $scheduleItems->every(fn ($item) => $item->status === PaymentScheduleStatus::PAID);
        $anyPaid = $scheduleItems->contains(fn ($item) => $item->status === PaymentScheduleStatus::PAID);

        if ($allPaid) {
            $debitNote->update(['status' => DebitNoteStatus::PAID]);
        } elseif ($anyPaid) {
            $debitNote->update(['status' => DebitNoteStatus::PARTIALLY_PAID]);
        } elseif ($debitNote->status !== DebitNoteStatus::ISSUED) {
            $debitNote->update(['status' => DebitNoteStatus::ISSUED]);
        }
    }

    /**
     * Sync the consumption state of credit notes touched by this allocation.
     *
     * A credit PSI (is_credit=true, sourced from CreditNoteLineItem) is
     * consumed either as a credit application (this allocation's
     * credit_schedule_item_id points at it) or as a cash refund (this
     * allocation pays it directly). recalculateStatus() skips is_credit
     * items, so their availability status is managed here: PAID only when
     * fully consumed (hides it from the available-credits list), PENDING
     * while any balance remains — including after a partial application or
     * when the consuming payment is cancelled.
     */
    protected function checkCreditNoteReconciliation(PaymentAllocation $allocation): void
    {
        foreach ($this->collectScheduleItems($allocation) as $scheduleItem) {
            if (! $scheduleItem || ! $scheduleItem->is_credit) {
                continue;
            }

            if ($scheduleItem->source_type !== CreditNoteLineItem::class) {
                continue;
            }

            if ($scheduleItem->status !== PaymentScheduleStatus::WAIVED) {
                $consumed = $scheduleItem->credit_consumed_amount;
                $remaining = $scheduleItem->credit_remaining_amount;

                $newStatus = ($consumed > 0 && $remaining <= CreditNote::SETTLEMENT_TOLERANCE)
                    ? PaymentScheduleStatus::PAID
                    : PaymentScheduleStatus::PENDING;

                if ($scheduleItem->status !== $newStatus) {
                    $scheduleItem->update(['status' => $newStatus->value]);
                }
            }

            $lineItem = CreditNoteLineItem::find($scheduleItem->source_id);
            $lineItem?->creditNote?->recalculateStatus();
        }
    }

    /**
     * Reflect the live PSI status onto the parent AdditionalCost. A cost can
     * have up to three PSIs — client-billable, forwarder-payable, and
     * supplier-payable — each mapped to its own column on the AdditionalCost
     * row so the user can see which side has been settled.
     *
     * The forwarder-side PSI is identified by the [forwarder-payable] tag and
     * the supplier-side PSI by the [supplier-payable] tag, both stitched into
     * PSI.notes by GeneratePaymentScheduleAction / SyncSupplierPayableScheduleItemAction.
     */
    protected function syncAdditionalCostStatus(PaymentAllocation $allocation): void
    {
        foreach ($this->collectScheduleItems($allocation) as $scheduleItem) {
            if (! $scheduleItem || $scheduleItem->source_type !== AdditionalCost::class) {
                continue;
            }

            /** @var AdditionalCost|null $cost */
            $cost = AdditionalCost::find($scheduleItem->source_id);
            if (! $cost) {
                continue;
            }

            $notes = $scheduleItem->notes ?? '';
            $newStatus = $this->mapScheduleItemStatusToCostStatus($scheduleItem->status);

            $column = match (true) {
                str_contains($notes, PaymentScheduleItem::FORWARDER_PAYABLE_TAG) => 'forwarder_status',
                str_contains($notes, PaymentScheduleItem::SUPPLIER_PAYABLE_TAG) => 'supplier_payable_status',
                default => 'status',
            };
            if ($cost->{$column} !== $newStatus) {
                $cost->{$column} = $newStatus;
                $cost->save();
            }
        }
    }

    /**
     * Sync status of shipment-owned mirror schedule items when a payment is
     * recorded against a PI/PO schedule item. The shipment mirror's paid_amount
     * accessor already includes mirror allocations, so we just trigger a
     * recalculation based on the current state.
     */
    protected function syncShipmentMirrorStatus(PaymentScheduleItem $scheduleItem): void
    {
        // Only relevant if the paid item is PI/PO owned and linked to a shipment
        if (! $scheduleItem->shipment_id || ! $scheduleItem->payment_term_stage_id) {
            return;
        }

        if ($scheduleItem->payable_type === Shipment::class) {
            return; // Shipment-owned itself — nothing to mirror
        }

        $shipmentMirrors = PaymentScheduleItem::where('payable_type', Shipment::class)
            ->where('payable_id', $scheduleItem->shipment_id)
            ->where('shipment_id', $scheduleItem->shipment_id)
            ->where('payment_term_stage_id', $scheduleItem->payment_term_stage_id)
            ->get();

        foreach ($shipmentMirrors as $mirror) {
            if ($mirror->status === PaymentScheduleStatus::WAIVED) {
                continue;
            }

            $mirror->refresh();

            if ($mirror->is_paid_in_full) {
                $newStatus = PaymentScheduleStatus::PAID;
            } elseif ($mirror->paid_amount > 0) {
                $newStatus = PaymentScheduleStatus::DUE;
            } else {
                $newStatus = PaymentScheduleStatus::PENDING;
            }

            if ($mirror->status !== $newStatus) {
                $mirror->update(['status' => $newStatus]);
            }
        }
    }

    /**
     * @return iterable<PaymentScheduleItem|null>
     */
    protected function collectScheduleItems(PaymentAllocation $allocation): iterable
    {
        yield $allocation->scheduleItem;

        if ($allocation->credit_schedule_item_id) {
            yield PaymentScheduleItem::find($allocation->credit_schedule_item_id);
        }
    }

    protected function mapScheduleItemStatusToCostStatus(?PaymentScheduleStatus $status): AdditionalCostStatus
    {
        return match ($status) {
            PaymentScheduleStatus::PAID => AdditionalCostStatus::PAID,
            PaymentScheduleStatus::WAIVED => AdditionalCostStatus::WAIVED,
            PaymentScheduleStatus::DUE,
            PaymentScheduleStatus::OVERDUE => AdditionalCostStatus::INVOICED,
            default => AdditionalCostStatus::PENDING,
        };
    }
}
