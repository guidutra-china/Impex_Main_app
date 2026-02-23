<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Convert all monetary integer fields from scale 100 (2 decimals) to scale 10000 (4 decimals).
 *
 * Every existing value is multiplied by 100 to preserve its real-world amount.
 * Example: $12.50 was stored as 1250, now stored as 125000.
 *
 * All application code must use / 10000 and * 10000 instead of / 100 and * 100.
 */
return new class extends Migration
{
    private array $tables = [
        'company_product' => ['unit_price'],
        'quotation_items' => ['unit_cost', 'unit_price'],
        'quotation_item_suppliers' => ['unit_cost'],
        'inquiry_items' => ['target_price'],
        'supplier_quotation_items' => ['unit_cost', 'total_cost'],
        'proforma_invoice_items' => ['unit_price', 'unit_cost'],
        'purchase_order_items' => ['unit_cost'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->update([
                    $column => DB::raw("{$column} * 100"),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->update([
                    $column => DB::raw("FLOOR({$column} / 100)"),
                ]);
            }
        }
    }
};
