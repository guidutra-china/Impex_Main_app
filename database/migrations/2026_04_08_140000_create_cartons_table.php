<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50);
            $table->string('container_number', 50)->nullable();
            $table->integer('pallet_number')->nullable();
            $table->string('packaging_type', 30)->default('CARTON');
            $table->decimal('gross_weight', 10, 3)->nullable();
            $table->decimal('net_weight', 10, 3)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('volume', 10, 4)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shipment_id', 'label']);
            $table->index(['shipment_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartons');
    }
};
