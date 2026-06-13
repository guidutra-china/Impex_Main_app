<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            // Crédito a favor de cliente (reduz o que ele nos deve) ou de
            // fornecedor (reduz o que pagaremos a ele). Empresas com papel
            // duplo precisam da distinção explícita.
            $table->string('party_type', 20)->default('client');
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->bigInteger('total_amount')->default(0);
            $table->string('currency_code', 10);
            $table->string('status', 30)->default('draft');
            $table->timestamp('issued_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('company_id');
            $table->index('party_type');
        });

        Schema::create('credit_note_line_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignId('additional_cost_id')->nullable()->constrained('additional_costs')->nullOnDelete();
            $table->foreignId('proforma_invoice_id')->nullable()->constrained('proforma_invoices')->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('shipment_id')->nullable()->constrained('shipments')->nullOnDelete();
            $table->string('description', 255);
            $table->bigInteger('amount');
            $table->string('currency_code', 10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_line_items');
        Schema::dropIfExists('credit_notes');
    }
};
