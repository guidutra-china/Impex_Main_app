<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_company_id')->constrained('companies');
            $table->foreignId('shipment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 50)->unique();
            $table->string('status', 30)->default('draft');
            $table->date('planned_shipment_date')->nullable();
            $table->date('planned_eta')->nullable();
            $table->string('currency_code', 10)->default('USD');
            $table->json('container_constraints')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('shipment_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_item_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->bigInteger('unit_price');
            $table->bigInteger('line_total');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_plan_items');
        Schema::dropIfExists('shipment_plans');
    }
};
