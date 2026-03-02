<?php

namespace App\Domain\PurchaseOrders\Actions;

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Support\Collection;

class GeneratePurchaseOrdersAction
{
    public function execute(ProformaInvoice $pi): Collection
    {
        $pi->loadMissing(['items.supplierCompany', 'items.product']);

        $itemsBySupplier = $pi->items
            ->filter(fn ($item) => $item->supplier_company_id !== null)
            ->groupBy('supplier_company_id');

        $created = collect();

        foreach ($itemsBySupplier as $supplierId => $items) {
            $existing = PurchaseOrder::where('proforma_invoice_id', $pi->id)
                ->where('supplier_company_id', $supplierId)
                ->first();

            if ($existing) {
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

            $created->push($po);
        }

        return $created;
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
