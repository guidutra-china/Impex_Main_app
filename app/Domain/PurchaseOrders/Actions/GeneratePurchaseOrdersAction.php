<?php

namespace App\Domain\PurchaseOrders\Actions;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

class GeneratePurchaseOrdersAction
{
    public function execute(ProformaInvoice $pi): Collection
    {
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
                // Update existing PO items if PO is still in DRAFT or SENT
                if (in_array($existing->status, [PurchaseOrderStatus::DRAFT, PurchaseOrderStatus::SENT])) {
                    $this->syncPoItems($existing, $items);
                    $result->push($existing);
                }
                continue;
            }

            $po = PurchaseOrder::create([
                'proforma_invoice_id' => $pi->id,
                'supplier_company_id' => $supplierId,
                'currency_code' => $pi->currency_code,
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
                    'unit_cost' => $piItem->unit_cost,
                    'incoterm' => $piItem->incoterm,
                    'notes' => $piItem->notes,
                    'sort_order' => ++$sortOrder,
                ]);
            }

            $result->push($po);
        }

        return $result;
    }

    protected function syncPoItems(PurchaseOrder $po, Collection $piItems): void
    {
        $existingPoItems = $po->items()->get()->keyBy('proforma_invoice_item_id');

        $sortOrder = 0;
        foreach ($piItems as $piItem) {
            $sortOrder++;
            $poItem = $existingPoItems->get($piItem->id);

            if ($poItem) {
                $poItem->update([
                    'product_id'     => $piItem->product_id,
                    'description'    => $piItem->description,
                    'specifications' => $piItem->specifications,
                    'quantity'       => $piItem->quantity,
                    'unit'           => $piItem->unit,
                    'unit_cost'      => $piItem->unit_cost,
                    'incoterm'       => $piItem->incoterm,
                    'notes'          => $piItem->notes,
                    'sort_order'     => $sortOrder,
                ]);
            } else {
                PurchaseOrderItem::create([
                    'purchase_order_id'        => $po->id,
                    'product_id'               => $piItem->product_id,
                    'proforma_invoice_item_id' => $piItem->id,
                    'description'              => $piItem->description,
                    'specifications'           => $piItem->specifications,
                    'quantity'                 => $piItem->quantity,
                    'unit'                     => $piItem->unit,
                    'unit_cost'                => $piItem->unit_cost,
                    'incoterm'                 => $piItem->incoterm,
                    'notes'                    => $piItem->notes,
                    'sort_order'               => $sortOrder,
                ]);
            }
        }

        // Remove PO items no longer in PI (only if no shipments)
        $piItemIds = $piItems->pluck('id')->toArray();
        $po->items()
            ->whereNotIn('proforma_invoice_item_id', $piItemIds)
            ->whereDoesntHave('shipmentItems')
            ->delete();
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
