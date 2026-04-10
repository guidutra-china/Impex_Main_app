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
        $this->checkDebitNoteReconciliation($allocation);
    }

    public function deleted(PaymentAllocation $allocation): void
    {
        $this->checkDebitNoteReconciliation($allocation);
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
