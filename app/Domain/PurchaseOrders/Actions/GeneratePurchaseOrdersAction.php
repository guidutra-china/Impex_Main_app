<?php

namespace App\Domain\PurchaseOrders\Actions;

use App\Domain\Financial\Actions\SyncSupplierPayableScheduleItemAction;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

class GeneratePurchaseOrdersAction
{
    /**
     * Items skipped by the "never below allocated" guard during the last execute().
     *
     * @var Collection<int, array{po_item: PurchaseOrderItem, shipped: int, requested: int}>
     */
    protected Collection $skippedShippedItems;

    public function __construct()
    {
        $this->skippedShippedItems = collect();
    }

    /**
     * Statuses beyond SENT that may still be synced, but only when the user
     * explicitly asks for it (regra de negócio 2026-07-24: PIs mudam depois
     * da confirmação da PO, então o Workflow pode atualizar POs confirmadas
     * mediante confirmação explícita no modal).
     */
    public const CONFIRMED_SYNCABLE_STATUSES = [
        PurchaseOrderStatus::CONFIRMED,
        PurchaseOrderStatus::IN_PRODUCTION,
        PurchaseOrderStatus::AWAITING_SHIPMENT,
    ];

    public function execute(ProformaInvoice $pi, bool $updateConfirmed = false): Collection
    {
        $this->skippedShippedItems = collect();

        $syncableStatuses = [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::SENT];

        if ($updateConfirmed) {
            $syncableStatuses = array_merge($syncableStatuses, self::CONFIRMED_SYNCABLE_STATUSES);
        }

        $pi->loadMissing(['items.supplierCompany', 'items.product.suppliers']);

        // Resolve supplier for items that don't have one assigned
        foreach ($pi->items as $item) {
            if ($item->supplier_company_id === null && $item->product) {
                $preferred = $item->product->suppliers()
                    ->orderByDesc('company_product.is_preferred')
                    ->first();

                if ($preferred) {
                    $item->supplier_company_id = $preferred->id;
                    $item->save();
                }
            }
        }

        $itemsBySupplier = $pi->items
            ->filter(fn ($item) => $item->supplier_company_id !== null)
            ->groupBy('supplier_company_id');

        $result = collect();

        foreach ($itemsBySupplier as $supplierId => $items) {
            $existing = PurchaseOrder::where('proforma_invoice_id', $pi->id)
                ->where('supplier_company_id', $supplierId)
                ->first();

            if ($existing) {
                if (in_array($existing->status, $syncableStatuses)) {
                    $this->syncPoItems($existing, $items);
                    $result->push($existing);
                }

                continue;
            }

            $poCurrency = $this->resolveCurrencyForSupplierItems($items, $pi->currency_code);

            $po = PurchaseOrder::create([
                'proforma_invoice_id' => $pi->id,
                'supplier_company_id' => $supplierId,
                'currency_code' => $poCurrency,
                'incoterm' => $pi->incoterm,
                'payment_term_id' => $pi->payment_term_id,
                'issue_date' => now()->toDateString(),
                'responsible_user_id' => $pi->responsible_user_id,
            ]);

            $sortOrder = 0;

            foreach ($items as $piItem) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $piItem->product_id,
                    'proforma_invoice_item_id' => $piItem->id,
                    'description' => $piItem->description,
                    'specifications' => $piItem->specifications,
                    'quantity' => $piItem->quantity,
                    'unit' => $piItem->unit,
                    'unit_cost' => $this->resolveUnitCost($piItem, $poCurrency, $pi->currency_code),
                    'incoterm' => $piItem->incoterm,
                    'notes' => $piItem->notes,
                    'sort_order' => ++$sortOrder,
                ]);
            }

            $result->push($po);
        }

        // Custos com lado pagável a fornecedor ancoram na PO do fornecedor;
        // re-sincroniza para mover linhas criadas antes da PO existir.
        if ($result->isNotEmpty()) {
            $syncSupplierPayable = app(SyncSupplierPayableScheduleItemAction::class);

            $pi->additionalCosts()
                ->whereNotIn('status', ['waived'])
                ->get()
                ->filter(fn ($cost) => $cost->hasSupplierPayableSide())
                ->each(fn ($cost) => $syncSupplierPayable->execute($cost, $pi));
        }

        return $result;
    }

    protected function syncPoItems(PurchaseOrder $po, Collection $piItems): void
    {
        $existingPoItems = $po->items()->get()->keyBy('proforma_invoice_item_id');
        $poCurrency = $po->currency_code;
        $piCurrency = $po->proformaInvoice?->currency_code ?? $poCurrency;

        $sortOrder = 0;
        foreach ($piItems as $piItem) {
            $sortOrder++;
            $poItem = $existingPoItems->get($piItem->id);

            if ($poItem) {
                // Nunca reduzir a quantidade abaixo do que embarques (mesmo em
                // rascunho) já alocaram para esta linha — o item é pulado por
                // inteiro e reportado, para o usuário resolver o embarque antes.
                $allocated = $this->allocatedShipmentQuantity($poItem);

                if ($piItem->quantity < $allocated) {
                    $this->skippedShippedItems->push([
                        'po_item' => $poItem,
                        'shipped' => $allocated,
                        'requested' => $piItem->quantity,
                    ]);

                    continue;
                }

                $poItem->update([
                    'product_id' => $piItem->product_id,
                    'description' => $piItem->description,
                    'specifications' => $piItem->specifications,
                    'quantity' => $piItem->quantity,
                    'unit' => $piItem->unit,
                    'unit_cost' => $this->resolveUnitCost($piItem, $poCurrency, $piCurrency),
                    'incoterm' => $piItem->incoterm,
                    'notes' => $piItem->notes,
                    'sort_order' => $sortOrder,
                ]);
            } else {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $po->id,
                    'product_id' => $piItem->product_id,
                    'proforma_invoice_item_id' => $piItem->id,
                    'description' => $piItem->description,
                    'specifications' => $piItem->specifications,
                    'quantity' => $piItem->quantity,
                    'unit' => $piItem->unit,
                    'unit_cost' => $this->resolveUnitCost($piItem, $poCurrency, $piCurrency),
                    'incoterm' => $piItem->incoterm,
                    'notes' => $piItem->notes,
                    'sort_order' => $sortOrder,
                ]);
            }
        }

        // Remove PO items no longer in PI (only if no shipments and no
        // production schedule entries — both FKs are ON DELETE SET NULL and
        // would be silently orphaned).
        $piItemIds = $piItems->pluck('id')->toArray();
        $po->items()
            ->whereNotIn('proforma_invoice_item_id', $piItemIds)
            ->whereDoesntHave('shipmentItems')
            ->whereDoesntHave('productionScheduleEntries')
            ->delete();
    }

    /**
     * Quantity already allocated to this PO line by non-cancelled shipments.
     * Draft shipments count: lowering the PO below a planned allocation breaks
     * the shipment↔PO link integrity even before anything physically ships.
     */
    protected function allocatedShipmentQuantity(PurchaseOrderItem $poItem): int
    {
        return (int) $poItem->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->where('status', '!=', ShipmentStatus::CANCELLED->value))
            ->sum('quantity');
    }

    /**
     * @return Collection<int, array{po_item: PurchaseOrderItem, shipped: int, requested: int}>
     */
    public function getSkippedShippedItems(): Collection
    {
        return $this->skippedShippedItems;
    }

    /**
     * Determine the PO currency from the PI items' cost_currency_code.
     * If all items share the same cost currency, use it; otherwise fall back to the PI currency.
     */
    protected function resolveCurrencyForSupplierItems(Collection $items, string $piCurrency): string
    {
        $costCurrencies = $items
            ->pluck('cost_currency_code')
            ->filter()
            ->unique();

        if ($costCurrencies->count() === 1) {
            return $costCurrencies->first();
        }

        return $piCurrency;
    }

    /**
     * Return the correct unit_cost value for a PO item.
     * When the PO currency matches the item's cost_currency_code, use the original unit_cost.
     * Otherwise fall back to unit_cost_in_document_currency.
     */
    protected function resolveUnitCost(
        \App\Domain\ProformaInvoices\Models\ProformaInvoiceItem $piItem,
        string $poCurrency,
        string $piCurrency,
    ): int {
        if ($piItem->cost_currency_code && $piItem->cost_currency_code === $poCurrency) {
            return $piItem->unit_cost;
        }

        if ($poCurrency === $piCurrency) {
            return $piItem->unit_cost_in_document_currency ?? $piItem->unit_cost;
        }

        return $piItem->unit_cost;
    }

    public function getSkippedSuppliers(ProformaInvoice $pi): Collection
    {
        $pi->loadMissing('items');

        return $pi->items
            ->filter(fn ($item) => $item->supplier_company_id === null)
            ->values();
    }

    public function getExistingPOs(ProformaInvoice $pi): Collection
    {
        return PurchaseOrder::where('proforma_invoice_id', $pi->id)
            ->with('supplierCompany')
            ->get();
    }
}
