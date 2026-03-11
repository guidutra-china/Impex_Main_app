<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Models\PaymentTerm;
use Illuminate\Support\Facades\DB;

class ConfirmShipmentPlanAction
{
    public function execute(ShipmentPlan $plan): void
    {
        if ($plan->status !== ShipmentPlanStatus::DRAFT) {
            throw new \RuntimeException("Shipment Plan {$plan->reference} is not in draft status.");
        }

        if ($plan->items->isEmpty()) {
            throw new \RuntimeException("Shipment Plan {$plan->reference} has no items.");
        }

        DB::transaction(function () use ($plan) {
            $this->generatePaymentScheduleItems($plan);

            $plan->update(['status' => ShipmentPlanStatus::CONFIRMED]);
        });
    }

    protected function generatePaymentScheduleItems(ShipmentPlan $plan): void
    {
        $plan->linkedPaymentScheduleItems()
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        $itemsByPi = $plan->getItemsByProformaInvoice();

        foreach ($itemsByPi as $piId => $planItems) {
            $pi = ProformaInvoice::with('paymentTerm.stages')->find($piId);

            if (! $pi || ! $pi->paymentTerm) {
                continue;
            }

            $piShipmentValue = $planItems->sum('line_total');

            $this->createScheduleItemsForPi($plan, $pi, $piShipmentValue);
        }
    }

    protected function createScheduleItemsForPi(ShipmentPlan $plan, ProformaInvoice $pi, int $shipmentValue): void
    {
        $paymentTerm = $pi->paymentTerm;

        if (! $paymentTerm || $paymentTerm->stages->isEmpty()) {
            return;
        }

        $sortOffset = $plan->linkedPaymentScheduleItems()->max('sort_order') ?? 0;

        foreach ($paymentTerm->stages as $stage) {
            if (! $stage->calculation_base?->isShipmentDependent()) {
                continue;
            }

            $amount = (int) round($shipmentValue * ($stage->percentage / 100));

            $dueDate = $this->calculateDueDate($plan, $stage);

            $label = $this->generateLabel($stage, $pi->reference, $plan->reference);

            $isBlocking = in_array($stage->calculation_base->value, [
                'before_shipment',
            ]);

            PaymentScheduleItem::create([
                'payable_type' => get_class($pi),
                'payable_id' => $pi->getKey(),
                'shipment_plan_id' => $plan->id,
                'payment_term_stage_id' => $stage->id,
                'label' => $label,
                'percentage' => $stage->percentage,
                'amount' => $amount,
                'currency_code' => $pi->currency_code,
                'due_condition' => $stage->calculation_base,
                'due_date' => $dueDate,
                'status' => PaymentScheduleStatus::PENDING,
                'is_blocking' => $isBlocking,
                'sort_order' => ++$sortOffset,
            ]);
        }
    }

    protected function calculateDueDate(ShipmentPlan $plan, $stage): ?\Carbon\Carbon
    {
        return match ($stage->calculation_base->value) {
            'before_shipment' => $plan->planned_shipment_date
                ? $plan->planned_shipment_date->copy()->subDays(max($stage->days, 2))
                : null,
            'shipment_date' => $plan->planned_shipment_date
                ? ($stage->days > 0
                    ? $plan->planned_shipment_date->copy()->addDays($stage->days)
                    : $plan->planned_shipment_date->copy())
                : null,
            'delivery_date' => $plan->planned_eta
                ? ($stage->days > 0
                    ? $plan->planned_eta->copy()->addDays($stage->days)
                    : $plan->planned_eta->copy()->subDays(2))
                : null,
            'bl_date' => $plan->planned_shipment_date
                ? ($stage->days > 0
                    ? $plan->planned_shipment_date->copy()->addDays($stage->days)
                    : $plan->planned_shipment_date->copy())
                : null,
            default => null,
        };
    }

    protected function generateLabel($stage, string $piReference, string $planReference): string
    {
        $parts = [
            $stage->percentage . '%',
            $stage->calculation_base->getLabel(),
        ];

        if ($stage->days > 0) {
            $parts[] = '(+' . $stage->days . ' days)';
        }

        $parts[] = '[' . $planReference . ' / ' . $piReference . ']';

        return implode(' — ', $parts);
    }
}
