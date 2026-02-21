<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->string('currency_code', 3)->default('USD');
            $table->string('commission_type')->default('embedded');
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->boolean('show_suppliers')->default(false);
            $table->unsignedInteger('validity_days')->default(30);
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('selected_supplier_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->bigInteger('unit_cost')->default(0);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->bigInteger('unit_price')->default(0);
            $table->string('incoterm')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('quotation_item_suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_item_id')->constrained('quotation_items')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained('companies');
            $table->bigInteger('unit_cost')->default(0);
            $table->string('currency_code', 3)->default('USD');
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->unsignedInteger('moq')->nullable();
            $table->string('incoterm')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['quotation_item_id', 'company_id']);
        });

        Schema::create('quotation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->text('change_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['quotation_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_versions');
        Schema::dropIfExists('quotation_item_suppliers');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
    }
};
