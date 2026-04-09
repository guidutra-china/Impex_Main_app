<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_pallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_container_id')->nullable()->constrained('shipment_containers')->nullOnDelete();
            $table->string('label', 50); // Auto: PLT-001
            $table->integer('pallet_number')->nullable(); // Optional human number
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shipment_id', 'label']);
            $table->index(['shipment_id', 'sort_order']);
            $table->index('shipment_container_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_pallets');
    }
};
