<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->foreignId('shipment_plan_id')
                ->nullable()
                ->after('payable_id')
                ->constrained('shipment_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipment_plan_id');
        });
    }
};
