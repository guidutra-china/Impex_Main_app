<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->decimal('length_ft', 8, 2)->nullable();
            $table->decimal('width_ft', 8, 2)->nullable();
            $table->decimal('height_ft', 8, 2)->nullable();
            $table->decimal('max_weight_kg', 10, 2)->nullable();
            $table->decimal('cubic_capacity_cbm', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('code');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_types');
    }
};
