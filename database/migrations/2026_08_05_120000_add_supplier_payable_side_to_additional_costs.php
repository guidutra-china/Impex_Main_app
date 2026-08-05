<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            // Supplier payable side: amount Impex pays a supplier for this cost
            // (mirrors the forwarder_* columns used by FREIGHT costs).
            $table->bigInteger('supplier_payable_amount')->nullable()->after('forwarder_amount_in_document_currency');
            $table->string('supplier_payable_currency_code', 10)->nullable()->after('supplier_payable_amount');
            $table->decimal('supplier_payable_exchange_rate', 15, 8)->nullable()->after('supplier_payable_currency_code');
            $table->bigInteger('supplier_payable_amount_in_document_currency')->nullable()->after('supplier_payable_exchange_rate');
            $table->date('supplier_payable_due_date')->nullable()->after('supplier_payable_amount_in_document_currency');
            $table->string('supplier_payable_status', 30)->nullable()->after('forwarder_status');
        });
    }

    public function down(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            $table->dropColumn([
                'supplier_payable_amount',
                'supplier_payable_currency_code',
                'supplier_payable_exchange_rate',
                'supplier_payable_amount_in_document_currency',
                'supplier_payable_due_date',
                'supplier_payable_status',
            ]);
        });
    }
};
