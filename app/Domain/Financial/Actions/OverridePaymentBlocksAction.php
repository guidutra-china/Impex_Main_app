<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Database\Eloquent\Model;

class OverridePaymentBlocksAction
{
    /**
     * Mark every PO-blocking payment schedule item attached to the given
     * payable as overridden. Returns the number of items affected.
     *
     * Items that are already resolved (paid/waived), non-blocking, credit
     * items, or items whose due_condition is outside the PO cycle
     * (i.e. BEFORE_SHIPMENT) are skipped.
     *
     * The caller is responsible for permission checks. The acting user is
     * read from auth() — call this only inside an authenticated request.
     */
    public function execute(Model $payable, string $reason): int
    {
        $blocking = PaymentScheduleItem::blockingPurchaseOrderGeneration($payable);

        if (count($blocking) === 0) {
            return 0;
        }

        $now = now();
        $userId = auth()->id();

        $count = 0;
        foreach ($blocking as $item) {
            $item->update([
                'overridden_by'   => $userId,
                'overridden_at'   => $now,
                'override_reason' => $reason,
            ]);
            $count++;
        }

        return $count;
    }
}
