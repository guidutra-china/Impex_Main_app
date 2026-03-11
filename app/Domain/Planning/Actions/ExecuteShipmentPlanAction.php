<?php

namespace App\Domain\Planning\Actions;

use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;
use Illuminate\Support\Facades\DB;

class ExecuteShipmentPlanAction
{
    public function execute(ShipmentPlan $plan, ?Shipment $existingShipment = null): Shipment
    {
if ($plan->status !== ShipmentPlanStatus::CONFIRMED) {
            throw new \RuntimeException("Shipment Plan {$plan->reference} is not confirmed.");
        }

        if ($plan->hasBlockingPayments()) {
            throw new \RuntimeException("Shipment Plan {$plan->reference} has unpaid blocking payments.");
        }

        return DB::transaction(function () use ($plan, $existingShipment) {
            $shipment = $existingShipment ?? $this->createShipment($plan);

            $this->createShipmentItems($plan, $shipment);

            $plan->update([
                'status' => ShipmentPlanStatus::SHIPPED,
                'shipment_id' => $shipment->id,
            ]);

            return $shipment;
        });
    }

    protected function createShipment(ShipmentPlan $plan): Shipment
    {
        return Shipment::create([
            'company_id' => $plan->supplier_company_id,
            'issue_date' => now()->toDateString(),
            'status' => ShipmentStatus::DRAFT,
            'currency_code' => $plan->currency_code,
            'etd' => $plan->planned_shipment_date,
            'eta' => $plan->planned_eta,
            'notes' => "Created from Shipment Plan {$plan->reference}",
            'created_by' => auth()->id(),
        ]);
    }

    protected function createShipmentItems(ShipmentPlan $plan, Shipment $shipment): void
    {
        $plan->load('items.proformaInvoiceItem.purchaseOrderItem');

        $sortOrder = $shipment->items()->max('sort_order') ?? 0;

        foreach ($plan->items as $planItem) {
            $piItem = $planItem->proformaInvoiceItem;

            if (! $piItem) {
                continue;
            }

            $poItemId = $piItem->purchaseOrderItem?->id;

            if (! $poItemId) {
                throw new \RuntimeException(
                    "PI Item \"{$piItem->product_name}\" (ID: {$piItem->id}) has no linked Purchase Order Item. Generate POs before executing the shipment plan."
                );
            }

            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'proforma_invoice_item_id' => $piItem->id,
                'purchase_order_item_id' => $poItemId,
                'quantity' => $planItem->quantity,
                'sort_order' => ++$sortOrder,
            ]);
        }
    }
}
