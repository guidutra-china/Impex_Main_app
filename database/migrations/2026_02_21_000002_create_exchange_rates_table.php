<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('base_currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->foreignId('target_currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('rate', 18, 8);
            $table->decimal('inverse_rate', 18, 8)->nullable();
            $table->date('date');
            $table->string('source', 50)->default('manual');
            $table->string('source_name')->nullable();
            $table->string('status', 50)->default('approved');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['base_currency_id', 'target_currency_id', 'date'], 'exchange_rates_pair_date_index');
            $table->index(['status', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
