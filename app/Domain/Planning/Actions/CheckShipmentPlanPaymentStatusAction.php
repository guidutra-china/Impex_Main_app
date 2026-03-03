<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;
use Illuminate\Support\Facades\Log;

class CheckShipmentPlanPaymentStatusAction
{
    public function execute(PaymentScheduleItem $scheduleItem): void
    {
        if (! $scheduleItem->shipment_plan_id) {
            return;
        }

        $plan = $scheduleItem->shipmentPlan;

        if (! $plan || $plan->status !== ShipmentPlanStatus::PENDING_PAYMENT) {
            return;
        }

        if ($plan->canBeExecuted()) {
            $plan->update(['status' => ShipmentPlanStatus::READY_TO_SHIP]);

            Log::info('ShipmentPlan auto-transitioned to Ready to Ship', [
                'shipment_plan_id' => $plan->id,
                'reference' => $plan->reference,
                'trigger' => 'payment_schedule_item_' . $scheduleItem->id,
            ]);
        }
    }

    public function checkAllPlansForPayable(string $payableType, int $payableId): void
    {
        $affectedPlanIds = PaymentScheduleItem::where('payable_type', $payableType)
            ->where('payable_id', $payableId)
            ->whereNotNull('shipment_plan_id')
            ->pluck('shipment_plan_id')
            ->unique();

        foreach ($affectedPlanIds as $planId) {
            $plan = ShipmentPlan::find($planId);

            if (! $plan || $plan->status !== ShipmentPlanStatus::PENDING_PAYMENT) {
                continue;
            }

            if ($plan->canBeExecuted()) {
                $plan->update(['status' => ShipmentPlanStatus::READY_TO_SHIP]);

                Log::info('ShipmentPlan auto-transitioned to Ready to Ship', [
                    'shipment_plan_id' => $plan->id,
                    'reference' => $plan->reference,
                    'trigger' => 'bulk_check_for_payable',
                ]);
            }
        }
    }

    public function revertIfNeeded(PaymentScheduleItem $scheduleItem): void
    {
        if (! $scheduleItem->shipment_plan_id) {
            return;
        }

        $plan = $scheduleItem->shipmentPlan;

        if (! $plan || $plan->status !== ShipmentPlanStatus::READY_TO_SHIP) {
            return;
        }

        if ($plan->hasBlockingPayments()) {
            $plan->update(['status' => ShipmentPlanStatus::PENDING_PAYMENT]);

            Log::info('ShipmentPlan reverted to Pending Payment', [
                'shipment_plan_id' => $plan->id,
                'reference' => $plan->reference,
                'trigger' => 'payment_cancelled_or_rejected_' . $scheduleItem->id,
            ]);
        }
    }
}
