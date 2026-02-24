<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->foreignId('credit_schedule_item_id')
                ->nullable()
                ->after('payment_schedule_item_id')
                ->constrained('payment_schedule_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_schedule_item_id');
        });
    }
};
