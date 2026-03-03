<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE shipment_items si
            INNER JOIN proforma_invoice_items pii ON si.proforma_invoice_item_id = pii.id
            INNER JOIN purchase_order_items poi ON poi.proforma_invoice_item_id = pii.id
            SET si.purchase_order_item_id = poi.id
            WHERE si.purchase_order_item_id IS NULL
        ");
    }

    public function down(): void
    {
        // No rollback needed
    }
};
