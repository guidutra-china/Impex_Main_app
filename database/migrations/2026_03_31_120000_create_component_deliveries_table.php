<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_schedule_component_id')
                ->constrained('production_schedule_components')->cascadeOnDelete();
            $table->date('expected_date');
            $table->unsignedInteger('expected_qty');
            $table->unsignedInteger('received_qty')->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('production_schedule_component_id', 'comp_deliveries_component_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('component_deliveries');
    }
};
