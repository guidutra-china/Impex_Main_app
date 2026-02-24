<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['payment_schedule_item_id']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique('payment_allocation_unique');
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unique(
                ['payment_id', 'payment_schedule_item_id', 'credit_schedule_item_id'],
                'payment_allocation_unique'
            );

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();

            $table->foreign('payment_schedule_item_id')
                ->references('id')
                ->on('payment_schedule_items')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
            $table->dropForeign(['payment_schedule_item_id']);
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->dropUnique('payment_allocation_unique');
        });

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->unique(
                ['payment_id', 'payment_schedule_item_id'],
                'payment_allocation_unique'
            );

            $table->foreign('payment_id')
                ->references('id')
                ->on('payments')
                ->cascadeOnDelete();

            $table->foreign('payment_schedule_item_id')
                ->references('id')
                ->on('payment_schedule_items')
                ->cascadeOnDelete();
        });
    }
};
