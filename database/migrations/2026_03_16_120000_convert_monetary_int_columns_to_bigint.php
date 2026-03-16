<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Convert monetary INT columns to BIGINT to prevent overflow.
 *
 * With SCALE=10000, a unit_cost of $27.91 is stored as 279100.
 * Multiplied by quantity 25000, total_cost = 6,977,500,000 which
 * exceeds MySQL INT max (2,147,483,647).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotation_items', function (Blueprint $table) {
            $table->bigInteger('unit_cost')->default(0)->comment('Supplier unit price in minor units')->change();
            $table->bigInteger('total_cost')->default(0)->comment('Calculated: quantity * unit_cost')->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->bigInteger('unit_cost')->default(0)->change();
        });

        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->bigInteger('unit_price')->default(0)->change();
            $table->bigInteger('unit_cost')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_quotation_items', function (Blueprint $table) {
            $table->integer('unit_cost')->default(0)->comment('Supplier unit price in minor units')->change();
            $table->integer('total_cost')->default(0)->comment('Calculated: quantity * unit_cost')->change();
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->integer('unit_cost')->default(0)->change();
        });

        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->integer('unit_price')->default(0)->change();
            $table->integer('unit_cost')->default(0)->change();
        });
    }
};
