<?php

namespace App\Domain\PurchaseOrders\Actions;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;

class SyncSupplierProductPricesAction
{
    /**
     * Sync PO item costs to company_product pivot (role=supplier).
     * Creates the link if it doesn't exist, updates unit_price if it does.
     */
    public function execute(PurchaseOrder $po): void
    {
        $po->loadMissing('items');

        foreach ($po->items as $item) {
            if (! $item->product_id || $item->unit_cost <= 0) {
                continue;
            }

            CompanyProduct::updateOrCreate(
                [
                    'company_id' => $po->supplier_company_id,
                    'product_id' => $item->product_id,
                    'role' => 'supplier',
                ],
                [
                    'unit_price' => $item->unit_cost,
                    'currency_code' => $po->currency_code,
                ],
            );
        }
    }
}
