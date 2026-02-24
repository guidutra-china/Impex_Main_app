<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remove ghost migration entries (superseded by this reconciliation)
        DB::table('migrations')
            ->whereIn('migration', [
                '2026_02_22_100003_create_payment_allocations_table',
            ])
            ->delete();

        // Rebuild payment_allocations to match the model expectations
        Schema::dropIfExists('payment_allocations');

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')
                ->constrained('payments')
                ->cascadeOnDelete();
            $table->foreignId('payment_schedule_item_id')
                ->constrained('payment_schedule_items')
                ->cascadeOnDelete();
            $table->foreignId('credit_schedule_item_id')
                ->nullable()
                ->constrained('payment_schedule_items')
                ->nullOnDelete();
            $table->bigInteger('allocated_amount')
                ->comment('Amount allocated in payment currency (minor units)');
            $table->decimal('exchange_rate', 15, 8)
                ->nullable()
                ->comment('Rate to convert payment currency to document currency');
            $table->bigInteger('allocated_amount_in_document_currency')
                ->nullable()
                ->comment('Converted amount in document currency (minor units)');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(
                ['payment_id', 'payment_schedule_item_id', 'credit_schedule_item_id'],
                'payment_allocation_unique'
            );
            $table->index('payment_schedule_item_id');
        });

        // Note: financial_tables and constraint migrations remain in the migrations
        // table since they created other tables (payments, payment_schedule_items).
        // Only payment_allocations was rebuilt by this migration.
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
