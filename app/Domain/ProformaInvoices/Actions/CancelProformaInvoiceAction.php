<?php

namespace App\Domain\ProformaInvoices\Actions;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\Planning\Models\ShipmentPlanItem;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;

class CancelProformaInvoiceAction
{
    /**
     * Cancel a PI and all related records (POs, ShipmentPlans, PaymentSchedule).
     * Use this when calling from code that handles the full cancellation flow.
     */
    public function execute(ProformaInvoice $pi, ?string $notes = null): ProformaInvoice
    {
        return app(TransitionStatusAction::class)->execute(
            model: $pi,
            toStatus: ProformaInvoiceStatus::CANCELLED,
            notes: $notes,
            sideEffects: fn (ProformaInvoice $pi) => $this->cancelRelatedRecords($pi),
        );
    }

    /**
     * Cancel all related records of a PI.
     * Use this as a sideEffect callback when the transition is handled externally.
     */
    public function cancelRelatedRecords(ProformaInvoice $pi): void
    {
        $transitionAction = app(TransitionStatusAction::class);

        $this->cancelPurchaseOrders($pi, $transitionAction);
        $this->cancelShipmentPlans($pi, $transitionAction);
        $this->waivePendingPaymentScheduleItems($pi);
    }

    /**
     * Cancel POs that haven't been shipped yet.
     * POs already SHIPPED or COMPLETED are preserved.
     */
    protected function cancelPurchaseOrders(ProformaInvoice $pi, TransitionStatusAction $transitionAction): void
    {
        $cancellableStatuses = [
            PurchaseOrderStatus::DRAFT,
            PurchaseOrderStatus::SENT,
            PurchaseOrderStatus::CONFIRMED,
            PurchaseOrderStatus::IN_PRODUCTION,
        ];

        $pi->purchaseOrders()
            ->whereIn('status', $cancellableStatuses)
            ->each(function ($po) use ($transitionAction, $pi) {
                $transitionAction->execute(
                    model: $po,
                    toStatus: PurchaseOrderStatus::CANCELLED,
                    notes: "Auto-cancelled: PI {$pi->reference} was cancelled.",
                );
            });
    }

    /**
     * Cancel ShipmentPlans that haven't been shipped yet.
     */
    protected function cancelShipmentPlans(ProformaInvoice $pi, TransitionStatusAction $transitionAction): void
    {
        $cancellableStatuses = [
            ShipmentPlanStatus::DRAFT,
            ShipmentPlanStatus::CONFIRMED,
        ];

        $piItemIds = $pi->items()->pluck('id');

        if ($piItemIds->isEmpty()) {
            return;
        }

        $shipmentPlanIds = ShipmentPlanItem::whereIn('proforma_invoice_item_id', $piItemIds)
            ->pluck('shipment_plan_id')
            ->unique();

        if ($shipmentPlanIds->isEmpty()) {
            return;
        }

        ShipmentPlan::whereIn('id', $shipmentPlanIds)
            ->whereIn('status', $cancellableStatuses)
            ->each(function ($plan) use ($transitionAction, $pi) {
                $transitionAction->execute(
                    model: $plan,
                    toStatus: ShipmentPlanStatus::CANCELLED,
                    notes: "Auto-cancelled: PI {$pi->reference} was cancelled.",
                );
            });
    }

    /**
     * Waive all pending/due payment schedule items.
     * Paid and already waived items are preserved.
     */
    protected function waivePendingPaymentScheduleItems(ProformaInvoice $pi): void
    {
        $pi->paymentScheduleItems()
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->update([
                'status' => PaymentScheduleStatus::WAIVED,
                'waived_by' => auth()->id(),
                'waived_at' => now(),
                'notes' => "Auto-waived: PI {$pi->reference} was cancelled.",
            ]);
    }
}
