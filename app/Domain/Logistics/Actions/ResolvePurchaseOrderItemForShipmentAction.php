<?php

declare(strict_types=1);

namespace App\Domain\Logistics\Actions;

use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

/**
 * Escolhe a linha de PO que deve receber um item de embarque.
 *
 * O critério antigo era `->first()`, o que só funciona enquanto cada linha da
 * PI é atendida por uma PO só. Quando a linha é dividida entre duas POs (caso
 * PI-2026-00049 → PO-2026-00039 + PO-2026-00052), o `->first()` gruda TODOS os
 * embarques na PO mais antiga: a primeira PO fica com o dobro embarcado, a
 * segunda nunca recebe embarque nenhum, e o recálculo de cronograma cria uma
 * parcela ship-specific na PO errada.
 *
 * Aqui o critério é saldo: a linha de PO só recebe o item se ainda tiver
 * quantidade não embarcada, preferindo sempre a PO mais antiga que couber.
 *
 * Capacidade conta embarques em QUALQUER status (menos os apagados), e não só
 * os que já saíram: um rascunho segura a alocação daquela PO, senão dois
 * rascunhos disputariam a mesma linha. É de propósito diferente do
 * `countsAsShipped` dos widgets de fulfillment, que mede o que efetivamente
 * embarcou.
 */
class ResolvePurchaseOrderItemForShipmentAction
{
    /**
     * @param  int  $quantity  Quantidade que o item de embarque vai levar.
     * @param  int|null  $ignoreShipmentItemId  Item de embarque em edição — não
     *                                          pode consumir a própria capacidade.
     */
    public function execute(int $proformaInvoiceItemId, int $quantity = 1, ?int $ignoreShipmentItemId = null): ?PurchaseOrderItem
    {
        $candidates = PurchaseOrderItem::query()
            ->where('proforma_invoice_item_id', $proformaInvoiceItemId)
            ->whereHas('purchaseOrder')
            ->orderBy('purchase_order_id')
            ->orderBy('id')
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $remaining = $this->remainingByPoItem($candidates, $ignoreShipmentItemId);

        // 1. A primeira PO que comporta a quantidade inteira.
        foreach ($candidates as $candidate) {
            if ($remaining[$candidate->id] >= $quantity) {
                return $candidate;
            }
        }

        // 2. Nenhuma comporta tudo: a primeira que ainda tenha algum saldo.
        foreach ($candidates as $candidate) {
            if ($remaining[$candidate->id] > 0) {
                return $candidate;
            }
        }

        // 3. Todas cheias. O vínculo com a PO é obrigatório para o embarque
        //    existir, então devolvemos a primeira e deixamos o excesso aparecer
        //    no widget de fulfillment em vez de bloquear a operação.
        return $candidates->first();
    }

    /**
     * @param  Collection<int, PurchaseOrderItem>  $candidates
     * @return array<int, int>
     */
    private function remainingByPoItem(Collection $candidates, ?int $ignoreShipmentItemId): array
    {
        $query = ShipmentItem::query()
            ->whereIn('purchase_order_item_id', $candidates->pluck('id'))
            ->whereHas('shipment');

        if ($ignoreShipmentItemId !== null) {
            $query->whereKeyNot($ignoreShipmentItemId);
        }

        $shipped = $query->selectRaw('purchase_order_item_id, SUM(quantity) as total')
            ->groupBy('purchase_order_item_id')
            ->pluck('total', 'purchase_order_item_id');

        $remaining = [];

        foreach ($candidates as $candidate) {
            $remaining[$candidate->id] = (int) $candidate->quantity - (int) ($shipped[$candidate->id] ?? 0);
        }

        return $remaining;
    }
}
