<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Facades\DB;

class IssueDebitNoteAction
{
    /**
     * Issue a debit note: transition to ISSUED, update AdditionalCost statuses,
     * and create PaymentScheduleItems on the DebitNote itself (payable_type=DebitNote)
     * so the line items become allocatable in Accounts Receivable regardless of
     * whether the DN is anchored to a Proforma Invoice.
     */
    public function execute(DebitNote $debitNote): void
    {
        if ($debitNote->status !== DebitNoteStatus::DRAFT) {
            throw new \RuntimeException('Only DRAFT debit notes can be issued.');
        }

        if ($debitNote->lineItems()->count() === 0) {
            throw new \RuntimeException('Cannot issue an empty debit note.');
        }

        DB::transaction(function () use ($debitNote) {
            // 1. Transition status
            $debitNote->update([
                'status' => DebitNoteStatus::ISSUED,
                'issued_at' => now(),
            ]);

            // 2. Create a PaymentScheduleItem per line item, owned by the DN
            foreach ($debitNote->lineItems()->get() as $lineItem) {
                $this->createScheduleItem($debitNote, $lineItem);
            }

            // 3. Update linked AdditionalCost statuses to INVOICED
            $costIds = $debitNote->lineItems()
                ->whereNotNull('additional_cost_id')
                ->pluck('additional_cost_id')
                ->unique();

            if ($costIds->isNotEmpty()) {
                \App\Domain\Financial\Models\AdditionalCost::whereIn('id', $costIds)
                    ->update(['status' => AdditionalCostStatus::INVOICED->value]);
            }
        });
    }

    protected function createScheduleItem(DebitNote $debitNote, DebitNoteLineItem $lineItem): void
    {
        $existing = PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
            ->where('source_id', $lineItem->id)
            ->first();

        if ($existing) {
            $existing->update([
                'amount' => $lineItem->amount,
                'label' => $debitNote->reference.': '.$lineItem->description,
                'due_date' => $debitNote->due_date,
            ]);

            return;
        }

        $maxSort = PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $debitNote->id)
            ->max('sort_order') ?? 0;

        PaymentScheduleItem::create([
            'payable_type' => DebitNote::class,
            'payable_id' => $debitNote->id,
            'label' => $debitNote->reference.': '.$lineItem->description,
            'percentage' => 0,
            'amount' => $lineItem->amount,
            'currency_code' => $lineItem->currency_code,
            'due_date' => $debitNote->due_date,
            'status' => PaymentScheduleStatus::PENDING->value,
            'is_blocking' => false,
            'is_credit' => false,
            'source_type' => DebitNoteLineItem::class,
            'source_id' => $lineItem->id,
            'sort_order' => $maxSort + 1,
        ]);
    }
}
