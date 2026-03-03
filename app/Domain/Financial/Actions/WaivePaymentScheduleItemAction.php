<?php

namespace App\Domain\Financial\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Planning\Actions\CheckShipmentPlanPaymentStatusAction;

class WaivePaymentScheduleItemAction
{
    public function execute(PaymentScheduleItem $item, ?string $reason = null): void
    {
        $item->update([
            'status' => PaymentScheduleStatus::WAIVED,
            'waived_by' => auth()->id(),
            'waived_at' => now(),
            'notes' => $reason ?? $item->notes,
        ]);

        if ($item->shipment_plan_id) {
            (new CheckShipmentPlanPaymentStatusAction())->execute($item);
        }
    }
}
