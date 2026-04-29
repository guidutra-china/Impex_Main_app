<?php

namespace App\Domain\Logistics\Actions;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Support\Facades\Log;

/**
 * Promove automaticamente PI/PO para "shipped" quando todos os itens
 * estiverem totalmente embarcados (com base nos shipments em IN_TRANSIT/ARRIVED).
 * Idempotente: se a transição não for permitida, faz no-op.
 */
class SyncFulfillmentStatusAction
{
    public function __construct(
        private readonly TransitionStatusAction $transitionStatus,
    ) {}

    public function execute(Shipment $shipment): void
    {
        $shipment->loadMissing(['items.proformaInvoiceItem', 'items.purchaseOrderItem']);

        $proformaInvoiceIds = $shipment->items
            ->pluck('proformaInvoiceItem.proforma_invoice_id')
            ->filter()
            ->unique()
            ->all();

        $purchaseOrderIds = $shipment->items
            ->pluck('purchaseOrderItem.purchase_order_id')
            ->filter()
            ->unique()
            ->all();

        foreach ($proformaInvoiceIds as $piId) {
            $pi = ProformaInvoice::with('items.shipmentItems.shipment')->find($piId);

            if ($pi !== null) {
                $this->maybeTransition($pi, ProformaInvoiceStatus::SHIPPED);
            }
        }

        foreach ($purchaseOrderIds as $poId) {
            $po = PurchaseOrder::with('items.shipmentItems.shipment')->find($poId);

            if ($po !== null) {
                $this->maybeTransition($po, PurchaseOrderStatus::SHIPPED);
            }
        }
    }

    private function maybeTransition(ProformaInvoice|PurchaseOrder $document, ProformaInvoiceStatus|PurchaseOrderStatus $target): void
    {
        if (! $document->is_fully_shipped) {
            return;
        }

        if (! $document->canTransitionTo($target->value)) {
            return;
        }

        try {
            $this->transitionStatus->execute(
                $document,
                $target,
                __('messages.auto_transitioned_on_shipment_complete'),
            );
        } catch (\Throwable $e) {
            // Idempotente: nunca quebrar o fluxo de Shipment por falha de transição automática.
            Log::warning('SyncFulfillmentStatusAction: failed to auto-transition document', [
                'model' => $document::class,
                'id' => $document->getKey(),
                'target_status' => $target->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
