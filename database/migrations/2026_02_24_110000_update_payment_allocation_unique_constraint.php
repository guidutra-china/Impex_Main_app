<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique('payment_allocation_unique');

            $table->unique(
                ['payment_id', 'payment_schedule_item_id', 'credit_schedule_item_id'],
                'payment_allocation_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique('payment_allocation_unique');

            $table->unique(
                ['payment_id', 'payment_schedule_item_id'],
                'payment_allocation_unique'
            );
        });
    }
};
