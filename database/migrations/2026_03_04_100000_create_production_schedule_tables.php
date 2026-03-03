<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies');
            $table->string('reference', 50)->unique();
            $table->date('received_date')->nullable();
            $table->unsignedTinyInteger('version')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('production_schedule_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_item_id')->constrained()->cascadeOnDelete();
            $table->date('production_date');
            $table->integer('quantity');
            $table->timestamps();

            $table->index(['production_schedule_id', 'production_date'], 'ps_entries_schedule_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_schedule_entries');
        Schema::dropIfExists('production_schedules');
    }
};
