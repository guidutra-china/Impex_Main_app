<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 50);
            $table->foreignId('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->string('fee_type', 50)->default('none');
            $table->bigInteger('fixed_fee_amount')->default(0);
            $table->foreignId('fixed_fee_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('percentage_fee', 5, 2)->default(0);
            $table->string('processing_time', 50)->default('immediate');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
