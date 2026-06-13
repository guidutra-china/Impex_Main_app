<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Support\Facades\DB;

class IssueCreditNoteAction
{
    /**
     * Issue a credit note: transition to ISSUED and create credit
     * PaymentScheduleItems (is_credit=true) owned by the CreditNote itself,
     * so the credit becomes available for application against open schedule
     * items (via PaymentAllocation.credit_schedule_item_id) or for a cash
     * refund (Payment allocated directly to the credit item).
     */
    public function execute(CreditNote $creditNote): void
    {
        if ($creditNote->status !== CreditNoteStatus::DRAFT) {
            throw new \RuntimeException('Only DRAFT credit notes can be issued.');
        }

        if ($creditNote->lineItems()->count() === 0) {
            throw new \RuntimeException('Cannot issue an empty credit note.');
        }

        DB::transaction(function () use ($creditNote) {
            $creditNote->update([
                'status' => CreditNoteStatus::ISSUED,
                'issued_at' => now(),
            ]);

            foreach ($creditNote->lineItems()->get() as $lineItem) {
                $this->createScheduleItem($creditNote, $lineItem);
            }
        });
    }

    protected function createScheduleItem(CreditNote $creditNote, CreditNoteLineItem $lineItem): void
    {
        $existing = PaymentScheduleItem::where('source_type', CreditNoteLineItem::class)
            ->where('source_id', $lineItem->id)
            ->first();

        if ($existing) {
            $existing->update([
                'amount' => $lineItem->amount,
                'label' => $creditNote->reference.': '.$lineItem->description,
            ]);

            return;
        }

        $maxSort = PaymentScheduleItem::where('payable_type', CreditNote::class)
            ->where('payable_id', $creditNote->id)
            ->max('sort_order') ?? 0;

        PaymentScheduleItem::create([
            'payable_type' => CreditNote::class,
            'payable_id' => $creditNote->id,
            'label' => $creditNote->reference.': '.$lineItem->description,
            'percentage' => 0,
            'amount' => $lineItem->amount,
            'currency_code' => $lineItem->currency_code,
            'status' => PaymentScheduleStatus::PENDING->value,
            'is_blocking' => false,
            'is_credit' => true,
            'source_type' => CreditNoteLineItem::class,
            'source_id' => $lineItem->id,
            'sort_order' => $maxSort + 1,
        ]);
    }
}
