<?php

namespace App\Filament\Concerns;

use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quanto de uma linha de PO já embarcou, e em quais embarques.
 *
 * A conta soma duas coisas:
 *
 *   1. os itens de embarque vinculados a ESTA linha de PO;
 *   2. os itens órfãos (`purchase_order_item_id` nulo) do mesmo item da PI.
 *
 * O (2) existe porque `shipment_items.purchase_order_item_id` é `on delete set
 * null`: editar ou regerar uma PO apaga a linha antiga e o embarque perde o
 * vínculo silenciosamente. Mas o órfão só pode ser creditado a esta PO quando
 * ela é a ÚNICA cobrindo aquela linha da PI — se a linha foi dividida entre
 * duas POs, não há como saber de quem é, e creditar às duas faz cada PO exibir
 * o total embarcado da outra junto (caso PI-2026-00049 / PO-39 / PO-52).
 * Nesse caso preferimos subnotificar; `shipments:relink-po-items` conserta o
 * órfão quando ele é inequívoco.
 */
trait CountsPoShipmentFulfillment
{
    /** @var array<int, bool> */
    private array $soleCoverageCache = [];

    protected function shippedQuantityForPoItem(PurchaseOrderItem $poItem): int
    {
        return (int) $this->fulfillmentQuery($poItem)->sum('quantity');
    }

    /**
     * @return list<string>
     */
    protected function shipmentReferencesForPoItem(PurchaseOrderItem $poItem, bool $preferBlNumber = false): array
    {
        return $this->fulfillmentQuery($poItem)
            ->with('shipment')
            ->get()
            ->map(fn (ShipmentItem $si) => $preferBlNumber
                ? ($si->shipment?->bl_number ?: $si->shipment?->reference)
                : $si->shipment?->reference)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function fulfillmentQuery(PurchaseOrderItem $poItem): Builder
    {
        $creditOrphans = $poItem->proforma_invoice_item_id !== null
            && $this->poItemIsSoleCoverage($poItem);

        return ShipmentItem::query()
            ->where(function (Builder $q) use ($poItem, $creditOrphans) {
                $q->where('purchase_order_item_id', $poItem->id);

                if ($creditOrphans) {
                    $q->orWhere(fn (Builder $orphan) => $orphan
                        ->whereNull('purchase_order_item_id')
                        ->where('proforma_invoice_item_id', $poItem->proforma_invoice_item_id));
                }
            })
            ->whereHas('shipment', fn ($q) => $q->countsAsShipped());
    }

    /** Esta linha de PO é a única cobrindo o item da PI? */
    private function poItemIsSoleCoverage(PurchaseOrderItem $poItem): bool
    {
        $piItemId = (int) $poItem->proforma_invoice_item_id;

        return $this->soleCoverageCache[$piItemId] ??= PurchaseOrderItem::query()
            ->where('proforma_invoice_item_id', $piItemId)
            ->whereHas('purchaseOrder')
            ->count() === 1;
    }
}
