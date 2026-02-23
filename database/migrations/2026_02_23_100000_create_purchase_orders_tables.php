<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();

            $table->foreignId('proforma_invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('draft');
            $table->string('currency_code', 10)->default('USD');
            $table->string('incoterm', 10)->nullable();

            $table->date('issue_date')->nullable();
            $table->date('expected_delivery_date')->nullable();

            // Confirmation tracking (supplier confirms the PO)
            $table->string('confirmation_method', 30)->nullable();
            $table->string('confirmation_reference')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('shipping_instructions')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('supplier_company_id');
            $table->index('proforma_invoice_id');
            $table->unique(['proforma_invoice_id', 'supplier_company_id'], 'po_pi_supplier_unique');
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proforma_invoice_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('description')->nullable();
            $table->text('specifications')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('unit', 20)->default('pcs');
            $table->integer('unit_cost')->default(0); // supplier price in cents
            $table->string('incoterm', 10)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
