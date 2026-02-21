<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('account_name');
            $table->string('bank_name');
            $table->string('account_number')->nullable();
            $table->string('routing_number')->nullable();
            $table->string('swift_code', 11)->nullable();
            $table->string('iban', 34)->nullable();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('account_type', 50)->default('business');
            $table->string('status', 50)->default('active');
            $table->bigInteger('current_balance')->default(0);
            $table->bigInteger('available_balance')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('account_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
