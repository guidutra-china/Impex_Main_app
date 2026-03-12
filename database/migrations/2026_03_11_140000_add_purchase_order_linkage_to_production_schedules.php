<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedules', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')
                ->nullable()
                ->after('proforma_invoice_id')
                ->constrained()
                ->nullOnDelete();
        });

        Schema::table('production_schedule_entries', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_id')
                ->nullable()
                ->after('proforma_invoice_item_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('production_schedule_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });

        Schema::table('production_schedules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
