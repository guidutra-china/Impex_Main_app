<?php

namespace App\Domain\ProformaInvoices\Actions;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;

class SyncClientProductPricesAction
{
    /**
     * Sync PI item prices to company_product pivot (role=client).
     * Creates the link if it doesn't exist, updates unit_price if it does.
     */
    public function execute(ProformaInvoice $pi): void
    {
        $pi->loadMissing('items');

        foreach ($pi->items as $item) {
            if (! $item->product_id || $item->unit_price <= 0) {
                continue;
            }

            CompanyProduct::updateOrCreate(
                [
                    'company_id' => $pi->company_id,
                    'product_id' => $item->product_id,
                    'role' => 'client',
                ],
                [
                    'unit_price' => $item->unit_price,
                    'currency_code' => $pi->currency_code,
                ],
            );
        }
    }
}
