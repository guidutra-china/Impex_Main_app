<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Actions\RecalculatePaymentScheduleForShipmentAction;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Support\Facades\DB;

class ReconcileShipmentPlanAction
{
    public function execute(ShipmentPlan $plan): array
    {
        if (! $plan->shipment_id) {
            throw new \RuntimeException("Shipment Plan {$plan->reference} has no linked shipment.");
        }

        $shipment = $plan->shipment;

        if (! $shipment) {
            throw new \RuntimeException("Linked shipment not found for plan {$plan->reference}.");
        }

        $adjustments = [];

        DB::transaction(function () use ($plan, $shipment, &$adjustments) {
            $adjustments = $this->reconcilePayments($plan, $shipment);
            $this->recalculateRemainingBaseItems($shipment);
        });

        return $adjustments;
    }

    protected function reconcilePayments(ShipmentPlan $plan, Shipment $shipment): array
    {
        $adjustments = [];

        $planItemsByPi = $plan->items->groupBy(
            fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id
        );

        $shipmentItemsByPi = $shipment->items->groupBy(
            fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id
        );

        foreach ($planItemsByPi as $piId => $planItems) {
            $plannedValue = $planItems->sum('line_total');

            $actualItems = $shipmentItemsByPi->get($piId, collect());
            $actualValue = $actualItems->sum(function ($item) {
                $piItem = $item->proformaInvoiceItem;
                return $piItem ? $piItem->unit_price * $item->quantity : 0;
            });

            if ($plannedValue === $actualValue) {
                continue;
            }

            $difference = $actualValue - $plannedValue;

            $scheduleItems = PaymentScheduleItem::where('shipment_plan_id', $plan->id)
                ->where('payable_id', $piId)
                ->whereNotIn('status', [
                    PaymentScheduleStatus::PAID->value,
                    PaymentScheduleStatus::WAIVED->value,
                ])
                ->get();

            foreach ($scheduleItems as $scheduleItem) {
                if ($scheduleItem->allocations()->exists()) {
                    continue;
                }

                $newAmount = (int) round($actualValue * ($scheduleItem->percentage / 100));
                $oldAmount = $scheduleItem->amount;

                if ($newAmount !== $oldAmount) {
                    $scheduleItem->update(['amount' => $newAmount]);

                    $adjustments[] = [
                        'pi_id' => $piId,
                        'schedule_item_id' => $scheduleItem->id,
                        'label' => $scheduleItem->label,
                        'old_amount' => $oldAmount,
                        'new_amount' => $newAmount,
                        'difference' => $newAmount - $oldAmount,
                    ];
                }
            }

            $this->updateDueDatesFromShipment($plan, $shipment);
        }

        return $adjustments;
    }

    protected function recalculateRemainingBaseItems(Shipment $shipment): void
    {
        $shipment->loadMissing('items.proformaInvoiceItem.proformaInvoice.paymentTerm.stages');

        $piIds = $shipment->items
            ->map(fn ($item) => $item->proformaInvoiceItem?->proforma_invoice_id)
            ->filter()
            ->unique();

        foreach ($piIds as $piId) {
            $pi = ProformaInvoice::with('paymentTerm.stages')->find($piId);

            if (! $pi || ! $pi->paymentTerm) {
                continue;
            }

            app(RecalculatePaymentScheduleForShipmentAction::class)
                ->recalculateRemainingItems($pi, $pi->paymentTerm);
        }
    }

    protected function updateDueDatesFromShipment(ShipmentPlan $plan, Shipment $shipment): void
    {
        $scheduleItems = PaymentScheduleItem::where('shipment_plan_id', $plan->id)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->get();

        foreach ($scheduleItems as $item) {
            $newDueDate = match ($item->due_condition) {
                CalculationBase::SHIPMENT_DATE => $shipment->etd ?? $shipment->actual_departure,
                CalculationBase::DELIVERY_DATE => $shipment->eta
                    ? $shipment->eta->copy()->subDays(2)
                    : null,
                CalculationBase::BL_DATE => $shipment->etd ?? $shipment->actual_departure,
                default => null,
            };

            if ($newDueDate && (! $item->due_date || ! $item->due_date->equalTo($newDueDate))) {
                $stage = $item->paymentTermStage;
                $days = $stage?->days ?? 0;

                if ($days > 0 && $item->due_condition !== CalculationBase::DELIVERY_DATE) {
                    $newDueDate = $newDueDate->copy()->addDays($days);
                }

                $item->update(['due_date' => $newDueDate]);
            }
        }
    }
}
