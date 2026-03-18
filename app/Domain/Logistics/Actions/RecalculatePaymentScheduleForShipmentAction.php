<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use Illuminate\Support\Facades\DB;

class RecalculatePaymentScheduleForShipmentAction
{
    public function execute(Shipment $shipment): void
    {
        // Skip if shipment was created from a ShipmentPlan (those are handled by ConfirmShipmentPlanAction)
        if ($shipment->shipmentPlan()->exists()) {
            return;
        }

        $shipment->loadMissing('items.proformaInvoiceItem.proformaInvoice.paymentTerm.stages');

        $itemsByPi = $shipment->items
            ->filter(fn ($item) => $item->proformaInvoiceItem?->proforma_invoice_id)
            ->groupBy(fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id);

        if ($itemsByPi->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($shipment, $itemsByPi) {
            foreach ($itemsByPi as $piId => $shipmentItems) {
                $pi = $shipmentItems->first()->proformaInvoiceItem->proformaInvoice;

                if (! $pi->paymentTerm || $pi->paymentTerm->stages->isEmpty()) {
                    continue;
                }

                $shipmentValue = $shipmentItems->sum(function ($item) {
                    return $item->proformaInvoiceItem->unit_price * $item->quantity;
                });

                $this->recalculateForPi($shipment, $pi, $shipmentValue);
            }
        });
    }

    protected function recalculateForPi(Shipment $shipment, ProformaInvoice $pi, int $shipmentValue): void
    {
        $paymentTerm = $pi->paymentTerm;

        // 1. Delete existing shipment-linked items for this shipment (not paid/waived, no allocations)
        PaymentScheduleItem::where('shipment_id', $shipment->id)
            ->where('payable_id', $pi->id)
            ->where('payable_type', get_class($pi))
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        // 2. Create shipment-dependent items for this shipment's value
        if ($shipmentValue > 0) {
            $sortOffset = PaymentScheduleItem::where('payable_type', get_class($pi))
                ->where('payable_id', $pi->id)
                ->max('sort_order') ?? 0;

            foreach ($paymentTerm->stages as $stage) {
                if (! $stage->calculation_base?->isShipmentDependent()) {
                    continue;
                }

                $amount = (int) round($shipmentValue * ($stage->percentage / 100));
                $dueDate = $this->calculateDueDate($shipment, $stage);
                $label = $this->generateLabel($stage, $pi->reference, $shipment->reference);

                $isBlocking = $stage->calculation_base === CalculationBase::BEFORE_SHIPMENT;

                PaymentScheduleItem::create([
                    'payable_type' => get_class($pi),
                    'payable_id' => $pi->id,
                    'shipment_id' => $shipment->id,
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

        // 3. Recalculate the PI's base (unshipped) shipment-dependent items
        $this->recalculateRemainingItems($pi, $paymentTerm);
    }

    public function recalculateRemainingItems(ProformaInvoice $pi, PaymentTerm $paymentTerm): void
    {
        // Total value shipped across ALL shipments for this PI
        $totalShippedValue = (int) ShipmentItem::query()
            ->whereHas('proformaInvoiceItem', fn ($q) => $q->where('proforma_invoice_id', $pi->id))
            ->get()
            ->sum(function ($item) {
                return $item->proformaInvoiceItem->unit_price * $item->quantity;
            });

        $remainingValue = max(0, $pi->total - $totalShippedValue);

        // Find orphaned SP items (plan not linked to any shipment)
        $orphanedSpIds = ShipmentPlan::whereNull('shipment_id')
            ->whereHas('items.proformaInvoiceItem', fn ($q) => $q->where('proforma_invoice_id', $pi->id))
            ->pluck('id');

        foreach ($paymentTerm->stages as $stage) {
            if (! $stage->calculation_base?->isShipmentDependent()) {
                continue;
            }

            // Update orphaned SP items for this stage
            if ($orphanedSpIds->isNotEmpty()) {
                $orphanedItems = PaymentScheduleItem::where('payable_type', get_class($pi))
                    ->where('payable_id', $pi->id)
                    ->where('payment_term_stage_id', $stage->id)
                    ->whereIn('shipment_plan_id', $orphanedSpIds)
                    ->whereNull('source_type')
                    ->whereNotIn('status', [
                        PaymentScheduleStatus::PAID->value,
                        PaymentScheduleStatus::WAIVED->value,
                    ])
                    ->whereDoesntHave('allocations')
                    ->get();

                foreach ($orphanedItems as $orphanedItem) {
                    if ($remainingValue <= 0) {
                        $orphanedItem->delete();
                    } else {
                        $spNewAmount = (int) round($remainingValue * ($stage->percentage / 100));
                        $orphanedItem->update(['amount' => $spNewAmount]);
                    }
                }
            }

            // Update the PI's base item for this stage (no shipment, no plan)
            $baseItem = PaymentScheduleItem::where('payable_type', get_class($pi))
                ->where('payable_id', $pi->id)
                ->where('payment_term_stage_id', $stage->id)
                ->whereNull('shipment_plan_id')
                ->whereNull('shipment_id')
                ->whereNull('source_type')
                ->first();

            if (! $baseItem) {
                continue;
            }

            if ($baseItem->status === PaymentScheduleStatus::PAID
                || $baseItem->status === PaymentScheduleStatus::WAIVED
                || $baseItem->allocations()->exists()) {
                continue;
            }

            // If orphaned SP items exist, the base item is redundant — delete it
            if ($orphanedSpIds->isNotEmpty() && $remainingValue > 0) {
                $baseItem->delete();
                continue;
            }

            if ($remainingValue <= 0) {
                $baseItem->delete();
            } else {
                $newAmount = (int) round($remainingValue * ($stage->percentage / 100));
                $baseItem->update([
                    'amount' => $newAmount,
                    'label' => $this->generateLabel($stage, $pi->reference, null) . ' [remaining]',
                ]);
            }
        }
    }

    protected function calculateDueDate(Shipment $shipment, $stage): ?\Carbon\Carbon
    {
        return match ($stage->calculation_base) {
            CalculationBase::BEFORE_SHIPMENT => $shipment->etd
                ? $shipment->etd->copy()->subDays(max($stage->days, 2))
                : null,
            CalculationBase::SHIPMENT_DATE => $shipment->etd
                ? ($stage->days > 0
                    ? $shipment->etd->copy()->addDays($stage->days)
                    : $shipment->etd->copy())
                : null,
            CalculationBase::DELIVERY_DATE => $shipment->eta
                ? ($stage->days > 0
                    ? $shipment->eta->copy()->addDays($stage->days)
                    : $shipment->eta->copy())
                : null,
            CalculationBase::BL_DATE => $shipment->etd
                ? ($stage->days > 0
                    ? $shipment->etd->copy()->addDays($stage->days)
                    : $shipment->etd->copy())
                : null,
            default => null,
        };
    }

    protected function generateLabel($stage, string $piReference, ?string $shipmentReference): string
    {
        $parts = [
            $stage->percentage . '%',
            $stage->calculation_base->getLabel(),
        ];

        if ($stage->days > 0) {
            $parts[] = '(+' . $stage->days . ' days)';
        }

        if ($shipmentReference) {
            $parts[] = '[' . $shipmentReference . ' / ' . $piReference . ']';
        } else {
            $parts[] = '[' . $piReference . ']';
        }

        return implode(' — ', $parts);
    }
}
