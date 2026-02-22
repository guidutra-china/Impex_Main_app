<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();

            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies')->comment('Supplier company');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();

            $table->string('status', 30)->default('requested');
            $table->string('currency_code', 10)->default('USD');

            $table->string('supplier_reference', 100)->nullable()->comment('Supplier own quotation number');
            $table->date('requested_at')->nullable();
            $table->date('received_at')->nullable();
            $table->date('valid_until')->nullable();

            $table->integer('lead_time_days')->nullable()->comment('Supplier lead time in days');
            $table->integer('moq')->nullable()->comment('Minimum order quantity');
            $table->string('incoterm', 20)->nullable();
            $table->string('payment_terms', 255)->nullable()->comment('Supplier payment terms as text');

            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['inquiry_id', 'company_id']);
            $table->index('status');
        });

        Schema::create('supplier_quotation_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_quotation_id')->constrained('supplier_quotations')->cascadeOnDelete();
            $table->foreignId('inquiry_item_id')->nullable()->constrained('inquiry_items')->nullOnDelete()->comment('Link to original inquiry item');
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();

            $table->string('description', 500)->nullable();
            $table->integer('quantity')->default(1);
            $table->string('unit', 20)->default('pcs');
            $table->integer('unit_cost')->default(0)->comment('Supplier unit price in minor units (cents)');
            $table->integer('total_cost')->default(0)->comment('Calculated: quantity * unit_cost');

            $table->integer('moq')->nullable()->comment('Item-level MOQ if different from header');
            $table->integer('lead_time_days')->nullable()->comment('Item-level lead time if different from header');

            $table->text('specifications')->nullable()->comment('Supplier-provided specs for this item');
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);

            $table->timestamps();

            $table->index('supplier_quotation_id');
            $table->index('inquiry_item_id');
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_quotation_items');
        Schema::dropIfExists('supplier_quotations');
    }
};
