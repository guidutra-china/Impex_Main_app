<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->morphs('payable');
            $table->foreignId('payment_term_stage_id')
                ->nullable()
                ->constrained('payment_term_stages')
                ->nullOnDelete();
            $table->string('label', 100);
            $table->unsignedTinyInteger('percentage');
            $table->bigInteger('amount')->default(0)->comment('In minor units (scale 10000)');
            $table->string('currency_code', 10);
            $table->string('due_condition', 50)->nullable()->comment('CalculationBase value');
            $table->date('due_date')->nullable();
            $table->string('status', 30)->default('pending');
            $table->boolean('is_blocking')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('waived_at')->nullable();
            $table->timestamps();

            $table->index(['payable_type', 'payable_id', 'status']);
            $table->index('due_date');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_schedule_item_id')
                ->nullable()
                ->constrained('payment_schedule_items')
                ->nullOnDelete();
            $table->morphs('payable');
            $table->string('direction', 20)->comment('inbound (from client) or outbound (to supplier)');
            $table->bigInteger('amount')->comment('In minor units (scale 10000) in payment currency');
            $table->string('currency_code', 10)->comment('Currency of the actual payment');
            $table->decimal('exchange_rate', 15, 8)->nullable()->comment('Rate to convert to document currency');
            $table->bigInteger('amount_in_document_currency')->nullable()->comment('Converted amount in document currency');
            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->nullOnDelete();
            $table->foreignId('bank_account_id')
                ->nullable()
                ->constrained('bank_accounts')
                ->nullOnDelete();
            $table->date('payment_date');
            $table->string('reference', 255)->nullable()->comment('SWIFT number, transfer ref, etc.');
            $table->string('status', 30)->default('pending_approval');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->string('attachment_path', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['payable_type', 'payable_id']);
            $table->index('status');
            $table->index('payment_date');
            $table->index('direction');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_schedule_items');
    }
};
