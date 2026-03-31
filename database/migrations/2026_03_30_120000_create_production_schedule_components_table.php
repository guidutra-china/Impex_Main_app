<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_schedule_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_schedule_id')
                ->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_item_id')
                ->constrained()->cascadeOnDelete();
            $table->string('component_name')->nullable();
            $table->string('status', 30)->default('at_supplier');
            $table->string('supplier_name')->nullable();
            $table->date('eta')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['production_schedule_id', 'proforma_invoice_item_id'], 'ps_components_schedule_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_schedule_components');
    }
};
