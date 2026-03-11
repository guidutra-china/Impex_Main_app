<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill any remaining nulls by looking up via proforma_invoice_item_id
        if (DB::getDriverName() === 'sqlite') {
            DB::statement("
                UPDATE shipment_items
                SET purchase_order_item_id = (
                    SELECT poi.id
                    FROM proforma_invoice_items pii
                    INNER JOIN purchase_order_items poi ON poi.proforma_invoice_item_id = pii.id
                    WHERE shipment_items.proforma_invoice_item_id = pii.id
                    LIMIT 1
                )
                WHERE purchase_order_item_id IS NULL
            ");
        } else {
            DB::statement("
                UPDATE shipment_items si
                INNER JOIN proforma_invoice_items pii ON si.proforma_invoice_item_id = pii.id
                INNER JOIN purchase_order_items poi ON poi.proforma_invoice_item_id = pii.id
                SET si.purchase_order_item_id = poi.id
                WHERE si.purchase_order_item_id IS NULL
            ");
        }
    }

    public function down(): void
    {
        // No rollback needed
    }
};
