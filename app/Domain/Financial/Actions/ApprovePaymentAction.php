<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;

class ApprovePaymentAction
{
    public function __construct(
        protected ReconcileSettlementStateAction $reconciler,
    ) {}

    public function approve(Payment $payment): void
    {
        $payment->update([
            'status' => PaymentStatus::APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $this->reconciler->forPayment($payment);
    }

    public function reject(Payment $payment, ?string $reason = null): void
    {
        $payment->update([
            'status' => PaymentStatus::REJECTED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'notes' => $reason
                ? ($payment->notes ? $payment->notes."\n\nRejection: ".$reason : 'Rejection: '.$reason)
                : $payment->notes,
        ]);

        // Allocations of a never-approved payment don't count toward paid
        // amounts, but reconciliation is idempotent — run it to cover the
        // edge of rejecting a previously approved record.
        $this->reconciler->forPayment($payment);
    }

    public function cancel(Payment $payment, ?string $reason = null): void
    {
        $wasApproved = $payment->status === PaymentStatus::APPROVED;

        $payment->update([
            'status' => PaymentStatus::CANCELLED,
            'notes' => $reason
                ? ($payment->notes ? $payment->notes."\n\nCancelled: ".$reason : 'Cancelled: '.$reason)
                : $payment->notes,
        ]);

        if ($wasApproved) {
            // paid_amount accessors filter by APPROVED, so reconciling now
            // rolls schedule items back and reverts DebitNote/AdditionalCost
            // state derived from them.
            $this->reconciler->forPayment($payment);
        }
    }
}
