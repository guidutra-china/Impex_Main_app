<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Facades\DB;

class IssueDebitNoteAction
{
    /**
     * Issue a debit note: transition to ISSUED, update AdditionalCost statuses,
     * and create PaymentScheduleItems on the respective PIs.
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

            // 2. Create payment schedule items for each line with a PI
            foreach ($debitNote->lineItems()->with('proformaInvoice')->get() as $lineItem) {
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
        $pi = $lineItem->proformaInvoice;

        if (! $pi) {
            // No PI reference — attach to the debit note's PI if available
            $pi = $debitNote->proformaInvoice;
        }

        if (! $pi) {
            return; // Cannot create schedule item without a PI
        }

        // Check if a schedule item already exists for this line item
        $existing = PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
            ->where('source_id', $lineItem->id)
            ->first();

        if ($existing) {
            $existing->update([
                'amount' => $lineItem->amount,
                'label' => $debitNote->reference . ': ' . $lineItem->description,
            ]);

            return;
        }

        // Get the next sort order
        $maxSort = PaymentScheduleItem::where('payable_type', get_class($pi))
            ->where('payable_id', $pi->id)
            ->max('sort_order') ?? 0;

        PaymentScheduleItem::create([
            'payable_type' => get_class($pi),
            'payable_id' => $pi->id,
            'label' => $debitNote->reference . ': ' . $lineItem->description,
            'percentage' => 0,
            'amount' => $lineItem->amount,
            'currency_code' => $lineItem->currency_code,
            'status' => 'pending',
            'is_blocking' => false,
            'is_credit' => false,
            'source_type' => DebitNoteLineItem::class,
            'source_id' => $lineItem->id,
            'sort_order' => $maxSort + 1,
        ]);
    }
}
