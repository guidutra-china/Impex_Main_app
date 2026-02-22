<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();

            $table->foreignId('inquiry_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained()->nullOnDelete();

            $table->string('status', 20)->default('draft');
            $table->string('currency_code', 10)->default('USD');
            $table->string('incoterm', 10)->nullable();

            $table->date('issue_date')->nullable();
            $table->date('valid_until')->nullable();
            $table->integer('validity_days')->nullable();

            $table->string('confirmation_method', 30)->nullable();
            $table->string('confirmation_reference')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('company_id');
            $table->index('inquiry_id');
        });

        Schema::create('proforma_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quotation_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_company_id')->nullable()->constrained('companies')->nullOnDelete();

            $table->string('description')->nullable();
            $table->text('specifications')->nullable();
            $table->integer('quantity')->default(0);
            $table->string('unit', 20)->default('pcs');
            $table->integer('unit_price')->default(0);
            $table->integer('unit_cost')->default(0);
            $table->string('incoterm', 10)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();
        });

        Schema::create('proforma_invoice_quotation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quotation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['proforma_invoice_id', 'quotation_id'], 'pi_quotation_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoice_quotation');
        Schema::dropIfExists('proforma_invoice_items');
        Schema::dropIfExists('proforma_invoices');
    }
};
