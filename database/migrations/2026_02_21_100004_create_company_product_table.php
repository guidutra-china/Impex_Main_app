<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20)->comment('supplier, client');
            $table->string('external_code', 100)->nullable()->comment('Company-specific product code');
            $table->string('external_name')->nullable()->comment('Company-specific product name');
            $table->bigInteger('unit_price')->default(0)->comment('Minor units (cents)');
            $table->string('currency_code', 3)->nullable()->comment('ISO 4217 currency code');
            $table->integer('lead_time_days')->nullable();
            $table->integer('moq')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();

            $table->unique(['company_id', 'product_id', 'role'], 'company_product_role_unique');
            $table->index('role');
            $table->index('external_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_product');
    }
};
