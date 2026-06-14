<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debit_notes', function (Blueprint $table) {
            // Existing rows are all client debit notes → default 'client'.
            $table->string('party_type', 20)->default('client')->after('company_id');
            $table->foreignId('purchase_order_id')->nullable()->after('proforma_invoice_id')
                ->constrained('purchase_orders')->nullOnDelete();
            $table->index('party_type');
        });

        Schema::table('debit_note_line_items', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('proforma_invoice_id')
                ->constrained('purchase_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debit_note_line_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });

        Schema::table('debit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropIndex(['party_type']);
            $table->dropColumn('party_type');
        });
    }
};
