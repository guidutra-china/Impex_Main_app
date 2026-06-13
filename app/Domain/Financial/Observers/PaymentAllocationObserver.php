<?php

namespace App\Domain\Financial\Observers;

use App\Domain\Financial\Actions\ReconcileSettlementStateAction;
use App\Domain\Financial\Models\PaymentAllocation;

/**
 * Note: mass-delete via Query Builder ($payment->allocations()->delete())
 * bypasses model events, so callers performing bulk deletion must invoke
 * PaymentScheduleItem::recalculateStatus() explicitly. This observer
 * covers the per-model creation/deletion path. Payment approval state
 * changes are reconciled by ApprovePaymentAction through the same shared
 * ReconcileSettlementStateAction.
 */
class PaymentAllocationObserver
{
    public function created(PaymentAllocation $allocation): void
    {
        app(ReconcileSettlementStateAction::class)->forAllocation($allocation);
    }

    public function deleted(PaymentAllocation $allocation): void
    {
        app(ReconcileSettlementStateAction::class)->forAllocation($allocation);
    }
}
