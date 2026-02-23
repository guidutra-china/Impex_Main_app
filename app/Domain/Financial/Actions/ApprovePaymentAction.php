<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;

class ApprovePaymentAction
{
    public function approve(Payment $payment): void
    {
        $payment->update([
            'status' => PaymentStatus::APPROVED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $this->updateAllocatedScheduleItems($payment);
    }

    public function reject(Payment $payment, ?string $reason = null): void
    {
        $payment->update([
            'status' => PaymentStatus::REJECTED,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'notes' => $reason
                ? ($payment->notes ? $payment->notes . "\n\nRejection: " . $reason : 'Rejection: ' . $reason)
                : $payment->notes,
        ]);
    }

    protected function updateAllocatedScheduleItems(Payment $payment): void
    {
        $allocations = $payment->allocations()->with('scheduleItem')->get();

        foreach ($allocations as $allocation) {
            $scheduleItem = $allocation->scheduleItem;

            if (! $scheduleItem) {
                continue;
            }

            if ($scheduleItem->is_paid_in_full) {
                $scheduleItem->update([
                    'status' => PaymentScheduleStatus::PAID,
                ]);
            }
        }
    }
}
