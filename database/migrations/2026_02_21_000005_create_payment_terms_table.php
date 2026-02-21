<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_default');
            $table->index('is_active');
        });

        Schema::create('payment_term_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_term_id')->constrained('payment_terms')->cascadeOnDelete();
            $table->unsignedTinyInteger('percentage');
            $table->unsignedSmallInteger('days')->default(0);
            $table->string('calculation_base', 50)->default('order_date');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('payment_term_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_term_stages');
        Schema::dropIfExists('payment_terms');
    }
};
