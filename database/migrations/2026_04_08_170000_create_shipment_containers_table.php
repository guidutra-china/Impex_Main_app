<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('label', 50); // Auto: CONT-001
            $table->string('container_number', 50)->nullable(); // CCLU7730065 etc.
            $table->string('type', 20)->nullable(); // 20GP, 40GP, 40HQ, 45HQ, LCL, AIR
            $table->string('seal_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['shipment_id', 'label']);
            $table->index(['shipment_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_containers');
    }
};
