<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RecalculatePaymentScheduleForShipmentAction
{
    public function execute(Shipment $shipment): void
    {
        // Skip if shipment was created from a ShipmentPlan (those are handled by ConfirmShipmentPlanAction)
        if ($shipment->shipmentPlan()->exists()) {
            return;
        }

        $shipment->loadMissing([
            'items.proformaInvoiceItem.proformaInvoice.paymentTerm.stages',
            'items.purchaseOrderItem.purchaseOrder.paymentTerm.stages',
        ]);

        DB::transaction(function () use ($shipment) {
            $this->recalculateForProformaInvoices($shipment);
            $this->recalculateForPurchaseOrders($shipment);
        });
    }

    protected function recalculateForProformaInvoices(Shipment $shipment): void
    {
        $itemsByPi = $shipment->items
            ->filter(fn ($item) => $item->proformaInvoiceItem?->proforma_invoice_id)
            ->groupBy(fn ($item) => $item->proformaInvoiceItem->proforma_invoice_id);

        foreach ($itemsByPi as $piId => $shipmentItems) {
            $pi = $shipmentItems->first()->proformaInvoiceItem->proformaInvoice;

            if (! $pi->paymentTerm || $pi->paymentTerm->stages->isEmpty()) {
                continue;
            }

            $shipmentValue = $shipmentItems->sum(function ($item) {
                return $item->proformaInvoiceItem->unit_price * $item->quantity;
            });

            $this->recalculateForDocument($shipment, $pi, $pi->paymentTerm, $shipmentValue);
            $this->recalculateRemainingItems($pi, $pi->paymentTerm);
        }
    }

    protected function recalculateForPurchaseOrders(Shipment $shipment): void
    {
        $itemsByPo = $shipment->items
            ->filter(fn ($item) => $item->purchaseOrderItem?->purchase_order_id)
            ->groupBy(fn ($item) => $item->purchaseOrderItem->purchase_order_id);

        foreach ($itemsByPo as $poId => $shipmentItems) {
            $po = $shipmentItems->first()->purchaseOrderItem->purchaseOrder;

            if (! $po->paymentTerm || $po->paymentTerm->stages->isEmpty()) {
                continue;
            }

            $shipmentValue = $shipmentItems->sum(function ($item) {
                return $item->purchaseOrderItem->unit_cost * $item->quantity;
            });

            $this->recalculateForDocument($shipment, $po, $po->paymentTerm, $shipmentValue);
            $this->recalculateRemainingPOItems($po, $po->paymentTerm);
        }
    }

    protected function recalculateForDocument(Shipment $shipment, Model $document, PaymentTerm $paymentTerm, int $shipmentValue): void
    {
        // 1. Delete existing shipment-linked items for this shipment (not paid/waived, no allocations)
        PaymentScheduleItem::where('shipment_id', $shipment->id)
            ->where('payable_id', $document->id)
            ->where('payable_type', get_class($document))
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->whereDoesntHave('allocations')
            ->delete();

        // 2. Create shipment-dependent items for this shipment's value
        if ($shipmentValue <= 0) {
            return;
        }

        $sortOffset = PaymentScheduleItem::where('payable_type', get_class($document))
            ->where('payable_id', $document->id)
            ->max('sort_order') ?? 0;

        foreach ($paymentTerm->stages as $stage) {
            if (! $stage->calculation_base?->isShipmentDependent()) {
                continue;
            }

            $amount = (int) round($shipmentValue * ($stage->percentage / 100));
            $dueDate = $this->calculateDueDate($shipment, $stage);
            $label = $this->generateLabel($stage, $document->reference, $shipment->reference);

            $isBlocking = $stage->calculation_base === CalculationBase::BEFORE_SHIPMENT;

            PaymentScheduleItem::create([
                'payable_type' => get_class($document),
                'payable_id' => $document->id,
                'shipment_id' => $shipment->id,
                'payment_term_stage_id' => $stage->id,
                'label' => $label,
                'percentage' => $stage->percentage,
                'amount' => $amount,
                'currency_code' => $document->currency_code,
                'due_condition' => $stage->calculation_base,
                'due_date' => $dueDate,
                'status' => PaymentScheduleStatus::PENDING,
                'is_blocking' => $isBlocking,
                'sort_order' => ++$sortOffset,
            ]);
        }
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

        $this->adjustBaseItems($pi, $paymentTerm, $remainingValue, $orphanedSpIds);
    }

    protected function recalculateRemainingPOItems(PurchaseOrder $po, PaymentTerm $paymentTerm): void
    {
        // Total value shipped across ALL shipments for this PO
        $totalShippedValue = (int) ShipmentItem::query()
            ->whereHas('purchaseOrderItem', fn ($q) => $q->where('purchase_order_id', $po->id))
            ->get()
            ->sum(function ($item) {
                return $item->purchaseOrderItem->unit_cost * $item->quantity;
            });

        $remainingValue = max(0, $po->total - $totalShippedValue);

        $this->adjustBaseItems($po, $paymentTerm, $remainingValue, collect());
    }

    protected function adjustBaseItems(Model $document, PaymentTerm $paymentTerm, int $remainingValue, $orphanedSpIds): void
    {
        foreach ($paymentTerm->stages as $stage) {
            if (! $stage->calculation_base?->isShipmentDependent()) {
                continue;
            }

            // Update orphaned SP items for this stage (PI only)
            if ($orphanedSpIds->isNotEmpty()) {
                $orphanedItems = PaymentScheduleItem::where('payable_type', get_class($document))
                    ->where('payable_id', $document->id)
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

            // Update the document's base item for this stage (no shipment, no plan)
            $baseItem = PaymentScheduleItem::where('payable_type', get_class($document))
                ->where('payable_id', $document->id)
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
                    'label' => $this->generateLabel($stage, $document->reference, null) . ' [remaining]',
                ]);
            }
        }
    }

    protected function calculateDueDate(Shipment $shipment, $stage): ?\Carbon\Carbon
    {
        $baseDate = match ($stage->calculation_base) {
            CalculationBase::BEFORE_SHIPMENT,
            CalculationBase::SHIPMENT_DATE,
            CalculationBase::BL_DATE => $shipment->etd,
            CalculationBase::DELIVERY_DATE => $shipment->eta,
            default => null,
        };

        if (! $baseDate) {
            return null;
        }

        if ($stage->calculation_base === CalculationBase::BEFORE_SHIPMENT) {
            return $baseDate->copy()->subDays(max($stage->days, 2));
        }

        return $stage->days != 0
            ? $baseDate->copy()->addDays($stage->days)
            : $baseDate->copy();
    }

    protected function generateLabel($stage, string $docReference, ?string $shipmentReference): string
    {
        $parts = [
            $stage->percentage . '%',
            $stage->calculation_base->getLabel(),
        ];

        if ($stage->days > 0) {
            $parts[] = '(+' . $stage->days . ' days)';
        } elseif ($stage->days < 0) {
            $parts[] = '(' . $stage->days . ' days)';
        }

        if ($shipmentReference) {
            $parts[] = '[' . $shipmentReference . ' / ' . $docReference . ']';
        } else {
            $parts[] = '[' . $docReference . ']';
        }

        return implode(' — ', $parts);
    }
}
