<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;

class UpdateShipmentPlanAction
{
    public function execute(ShipmentPlan $plan): void
    {
        if ($plan->status !== ShipmentPlanStatus::CONFIRMED) {
            return;
        }

        $hasLockedPayments = $plan->linkedPaymentScheduleItems()
            ->where(function ($q) {
                $q->whereIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ])->orWhereHas('allocations');
            })
            ->exists();

        if ($hasLockedPayments) {
            return;
        }

        $plan->linkedPaymentScheduleItems()
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        app(ConfirmShipmentPlanAction::class)->execute(
            $plan->fresh()->forceFill(['status' => ShipmentPlanStatus::DRAFT])
        );
    }
}
