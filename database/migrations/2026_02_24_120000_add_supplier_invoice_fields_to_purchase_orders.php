<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('supplier_invoice_number')->nullable()->after('confirmation_reference');
            $table->date('supplier_invoice_date')->nullable()->after('supplier_invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_invoice_number',
                'supplier_invoice_date',
            ]);
        });
    }
};
