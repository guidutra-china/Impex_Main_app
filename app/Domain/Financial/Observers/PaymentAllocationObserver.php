<?php

namespace App\Domain\Financial\Observers;

use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;

class PaymentAllocationObserver
{
    public function created(PaymentAllocation $allocation): void
    {
        $this->recalculateScheduleItemStatus($allocation);
        $this->checkDebitNoteReconciliation($allocation);
    }

    public function deleted(PaymentAllocation $allocation): void
    {
        $this->recalculateScheduleItemStatus($allocation);
        $this->checkDebitNoteReconciliation($allocation);
    }

    /**
     * Keep the schedule item's stored status in sync with its live
     * paid_amount. Without this, removing an allocation (for example by
     * editing a payment or cancelling it) leaves the item stuck at PAID
     * even after paid_amount falls back to 0.
     *
     * Note: mass-delete via Query Builder ($payment->allocations()->delete())
     * bypasses model events, so callers performing bulk deletion must invoke
     * PaymentScheduleItem::recalculateStatus() explicitly. This observer
     * covers the per-model deletion path.
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
}
