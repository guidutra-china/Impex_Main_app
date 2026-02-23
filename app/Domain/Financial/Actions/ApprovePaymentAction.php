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

        $this->recalculateScheduleItemStatuses($payment);
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

    public function cancel(Payment $payment, ?string $reason = null): void
    {
        $wasApproved = $payment->status === PaymentStatus::APPROVED;

        $payment->update([
            'status' => PaymentStatus::CANCELLED,
            'notes' => $reason
                ? ($payment->notes ? $payment->notes . "\n\nCancelled: " . $reason : 'Cancelled: ' . $reason)
                : $payment->notes,
        ]);

        if ($wasApproved) {
            $this->rollbackScheduleItemStatuses($payment);
        }
    }

    protected function recalculateScheduleItemStatuses(Payment $payment): void
    {
        $allocations = $payment->allocations()->with('scheduleItem')->get();

        foreach ($allocations as $allocation) {
            $scheduleItem = $allocation->scheduleItem;

            if (! $scheduleItem || $scheduleItem->status === PaymentScheduleStatus::WAIVED) {
                continue;
            }

            $scheduleItem->update([
                'status' => $scheduleItem->is_paid_in_full
                    ? PaymentScheduleStatus::PAID
                    : PaymentScheduleStatus::DUE,
            ]);
        }
    }

    protected function rollbackScheduleItemStatuses(Payment $payment): void
    {
        $allocations = $payment->allocations()->with('scheduleItem')->get();

        foreach ($allocations as $allocation) {
            $scheduleItem = $allocation->scheduleItem;

            if (! $scheduleItem || $scheduleItem->status === PaymentScheduleStatus::WAIVED) {
                continue;
            }

            // Recalculate: the cancelled payment's allocations no longer count
            // because paid_amount accessor only sums APPROVED payments
            $scheduleItem->refresh();

            if ($scheduleItem->is_paid_in_full) {
                $scheduleItem->update(['status' => PaymentScheduleStatus::PAID]);
            } elseif ($scheduleItem->paid_amount > 0) {
                $scheduleItem->update(['status' => PaymentScheduleStatus::DUE]);
            } else {
                $scheduleItem->update(['status' => PaymentScheduleStatus::PENDING]);
            }
        }
    }
}
