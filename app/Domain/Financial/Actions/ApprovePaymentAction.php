<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;

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
                ? ($payment->notes ? $payment->notes."\n\nRejection: ".$reason : 'Rejection: '.$reason)
                : $payment->notes,
        ]);
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
            $this->rollbackScheduleItemStatuses($payment);
        }
    }

    protected function recalculateScheduleItemStatuses(Payment $payment): void
    {
        $this->reconcileScheduleItems($payment);
    }

    protected function rollbackScheduleItemStatuses(Payment $payment): void
    {
        // After cancellation, paid_amount accessor returns 0 for this payment's
        // allocations (filter by APPROVED), so recalculateStatus correctly
        // transitions items back to PENDING/DUE. Same call path as recalc.
        $this->reconcileScheduleItems($payment);
    }

    protected function reconcileScheduleItems(Payment $payment): void
    {
        $allocations = $payment->allocations()->with('scheduleItem')->get();

        foreach ($allocations as $allocation) {
            $scheduleItem = $allocation->scheduleItem;

            if (! $scheduleItem) {
                continue;
            }

            $scheduleItem->recalculateStatus();

            $this->syncShipmentMirrorStatus($scheduleItem);
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
}
