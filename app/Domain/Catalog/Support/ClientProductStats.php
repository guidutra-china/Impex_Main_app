<?php

namespace App\Domain\Catalog\Support;

use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

/**
 * Visão geral de cada produto para um cliente: quantidades, preços pagos e
 * custos de fornecedor, agregados em poucas consultas (não por linha).
 *
 * Regras:
 * - Só contam PIs do cliente a partir de Confirmed (Draft, Sent e Cancelled
 *   ficam fora), a mesma régua dos recebimentos.
 * - Custo vem da PO ligada ao item da PI (POs canceladas fora); sem PO, usa
 *   o custo registrado no próprio item da PI.
 * - Máximo e média de preço/custo só comparam valores na moeda da compra
 *   mais recente; moedas diferentes são sinalizadas, não somadas.
 * - Embarcado = soma dos shipment items em embarques não cancelados.
 *
 * Valores monetários em inteiros na escala do Money.
 */
final class ClientProductStats
{
    public const COUNTED_PI_STATUSES = [
        ProformaInvoiceStatus::CONFIRMED,
        ProformaInvoiceStatus::SHIPPED,
        ProformaInvoiceStatus::FINALIZED,
        ProformaInvoiceStatus::REOPENED,
    ];

    /**
     * @return array<int, array{
     *     purchased_qty: int, shipped_qty: int, pi_count: int, last_purchase_date: ?string,
     *     price_currency: ?string, last_price: ?int, max_price: ?int, avg_price: ?int, mixed_price_currencies: bool,
     *     cost_currency: ?string, last_cost: ?int, max_cost: ?int, last_supplier: ?string, mixed_cost_currencies: bool,
     *     last_margin_pct: ?float
     * }> keyed by product_id; products never bought are absent.
     */
    public static function forCompany(Company $company): array
    {
        $items = ProformaInvoiceItem::query()
            ->whereNotNull('product_id')
            ->whereHas('proformaInvoice', fn ($q) => $q
                ->where('company_id', $company->id)
                ->whereIn('status', self::COUNTED_PI_STATUSES))
            ->with([
                'proformaInvoice:id,issue_date,currency_code',
                'supplierCompany:id,name',
            ])
            ->get(['id', 'proforma_invoice_id', 'product_id', 'quantity', 'unit_price', 'unit_cost', 'cost_currency_code', 'supplier_company_id']);

        if ($items->isEmpty()) {
            return [];
        }

        // Mais recente primeiro (data de emissão da PI, depois id).
        $items = $items->sortBy([
            fn ($a, $b) => strcmp((string) $b->proformaInvoice?->issue_date?->toDateString(), (string) $a->proformaInvoice?->issue_date?->toDateString())
                ?: ($b->id <=> $a->id),
        ])->values();

        $itemIds = $items->pluck('id')->all();

        $shippedByItem = ShipmentItem::query()
            ->whereIn('proforma_invoice_item_id', $itemIds)
            ->whereHas('shipment', fn ($q) => $q->where('status', '!=', ShipmentStatus::CANCELLED->value))
            ->selectRaw('proforma_invoice_item_id, SUM(quantity) AS qty')
            ->groupBy('proforma_invoice_item_id')
            ->pluck('qty', 'proforma_invoice_item_id');

        $poItems = PurchaseOrderItem::query()
            ->whereIn('proforma_invoice_item_id', $itemIds)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrderStatus::CANCELLED->value))
            ->with(['purchaseOrder:id,issue_date,currency_code,supplier_company_id', 'purchaseOrder.supplierCompany:id,name'])
            ->get(['id', 'purchase_order_id', 'proforma_invoice_item_id', 'unit_cost'])
            ->sortBy([
                fn ($a, $b) => strcmp((string) $b->purchaseOrder?->issue_date?->toDateString(), (string) $a->purchaseOrder?->issue_date?->toDateString())
                    ?: ($b->id <=> $a->id),
            ])
            ->values();

        $productOfItem = $items->pluck('product_id', 'id');
        $poItemsByProduct = $poItems->groupBy(fn ($po) => (int) $productOfItem[$po->proforma_invoice_item_id]);

        $stats = [];

        foreach ($items->groupBy('product_id') as $productId => $productItems) {
            /** @var Collection<int, ProformaInvoiceItem> $productItems */
            $latest = $productItems->first();
            $priceCurrency = (string) ($latest->proformaInvoice?->currency_code ?? '');
            $samePriceCurrency = $productItems->filter(fn ($i) => (string) ($i->proformaInvoice?->currency_code ?? '') === $priceCurrency);

            $qtySame = (int) $samePriceCurrency->sum('quantity');
            $weighted = (int) $samePriceCurrency->sum(fn ($i) => (int) $i->quantity * (int) $i->unit_price);

            $row = [
                'purchased_qty' => (int) $productItems->sum('quantity'),
                'shipped_qty' => (int) $productItems->sum(fn ($i) => (int) ($shippedByItem[$i->id] ?? 0)),
                'pi_count' => $productItems->pluck('proforma_invoice_id')->unique()->count(),
                'last_purchase_date' => $latest->proformaInvoice?->issue_date?->toDateString(),
                'price_currency' => $priceCurrency !== '' ? $priceCurrency : null,
                'last_price' => (int) $latest->unit_price,
                'max_price' => (int) $samePriceCurrency->max('unit_price'),
                'avg_price' => $qtySame > 0 ? (int) round($weighted / $qtySame) : null,
                'mixed_price_currencies' => $samePriceCurrency->count() !== $productItems->count(),
                'cost_currency' => null,
                'last_cost' => null,
                'max_cost' => null,
                'last_supplier' => null,
                'mixed_cost_currencies' => false,
                'last_margin_pct' => null,
            ];

            /** @var Collection<int, PurchaseOrderItem> $productPos */
            $productPos = $poItemsByProduct->get((int) $productId, collect());

            if ($productPos->isNotEmpty()) {
                $latestPo = $productPos->first();
                $costCurrency = (string) ($latestPo->purchaseOrder?->currency_code ?? '');
                $sameCostCurrency = $productPos->filter(fn ($po) => (string) ($po->purchaseOrder?->currency_code ?? '') === $costCurrency);

                $row['cost_currency'] = $costCurrency !== '' ? $costCurrency : null;
                $row['last_cost'] = (int) $latestPo->unit_cost;
                $row['max_cost'] = (int) $sameCostCurrency->max('unit_cost');
                $row['last_supplier'] = $latestPo->purchaseOrder?->supplierCompany?->name;
                $row['mixed_cost_currencies'] = $sameCostCurrency->count() !== $productPos->count();
            } else {
                $withCost = $productItems->filter(fn ($i) => (int) $i->unit_cost > 0);
                $latestCost = $withCost->first();

                if ($latestCost) {
                    $costCurrency = (string) ($latestCost->cost_currency_code ?: $latestCost->proformaInvoice?->currency_code ?: '');
                    $sameCostCurrency = $withCost->filter(fn ($i) => (string) ($i->cost_currency_code ?: $i->proformaInvoice?->currency_code ?: '') === $costCurrency);

                    $row['cost_currency'] = $costCurrency !== '' ? $costCurrency : null;
                    $row['last_cost'] = (int) $latestCost->unit_cost;
                    $row['max_cost'] = (int) $sameCostCurrency->max('unit_cost');
                    $row['last_supplier'] = $latestCost->supplierCompany?->name;
                    $row['mixed_cost_currencies'] = $sameCostCurrency->count() !== $withCost->count();
                }
            }

            if ($row['last_price'] > 0 && $row['last_cost'] !== null && $row['cost_currency'] === $row['price_currency']) {
                $row['last_margin_pct'] = round(($row['last_price'] - $row['last_cost']) / $row['last_price'] * 100, 1);
            }

            $stats[(int) $productId] = $row;
        }

        return $stats;
    }
}
