<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('avatar')->nullable();
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('hs_code', 20)->nullable()->comment('Harmonized System code');
            $table->string('origin_country', 2)->nullable()->comment('ISO 3166-1 alpha-2');
            $table->string('brand')->nullable();
            $table->string('model_number')->nullable();
            $table->integer('moq')->nullable()->comment('Minimum Order Quantity');
            $table->string('moq_unit', 20)->nullable()->default('pcs');
            $table->integer('lead_time_days')->nullable();
            $table->string('certifications')->nullable();
            $table->text('internal_notes')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index('status');
            $table->index('category_id');
            $table->index('parent_id');
            $table->index('hs_code');
        });

        Schema::create('product_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('net_weight', 10, 3)->nullable()->comment('kg');
            $table->decimal('gross_weight', 10, 3)->nullable()->comment('kg');
            $table->decimal('length', 10, 2)->nullable()->comment('cm');
            $table->decimal('width', 10, 2)->nullable()->comment('cm');
            $table->decimal('height', 10, 2)->nullable()->comment('cm');
            $table->string('material')->nullable();
            $table->string('color')->nullable();
            $table->string('finish')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('product_id');
        });

        Schema::create('product_packagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // Inner Box
            $table->integer('pcs_per_inner_box')->nullable();
            $table->decimal('inner_box_length', 10, 2)->nullable()->comment('cm');
            $table->decimal('inner_box_width', 10, 2)->nullable()->comment('cm');
            $table->decimal('inner_box_height', 10, 2)->nullable()->comment('cm');
            $table->decimal('inner_box_weight', 10, 3)->nullable()->comment('kg');
            // Master Carton
            $table->integer('pcs_per_carton')->nullable();
            $table->integer('inner_boxes_per_carton')->nullable();
            $table->decimal('carton_length', 10, 2)->nullable()->comment('cm');
            $table->decimal('carton_width', 10, 2)->nullable()->comment('cm');
            $table->decimal('carton_height', 10, 2)->nullable()->comment('cm');
            $table->decimal('carton_weight', 10, 3)->nullable()->comment('kg');
            $table->decimal('carton_cbm', 10, 4)->nullable();
            // Container Loading
            $table->integer('cartons_per_20ft')->nullable();
            $table->integer('cartons_per_40ft')->nullable();
            $table->integer('cartons_per_40hq')->nullable();
            $table->text('packing_notes')->nullable();
            $table->timestamps();

            $table->unique('product_id');
        });

        Schema::create('product_costings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('base_price')->nullable()->default(0)->comment('Minor units (cents)');
            $table->bigInteger('bom_material_cost')->nullable()->default(0)->comment('Minor units');
            $table->bigInteger('direct_labor_cost')->nullable()->default(0)->comment('Minor units');
            $table->bigInteger('direct_overhead_cost')->nullable()->default(0)->comment('Minor units');
            $table->bigInteger('total_manufacturing_cost')->nullable()->default(0)->comment('Minor units');
            $table->decimal('markup_percentage', 8, 2)->nullable()->default(0);
            $table->bigInteger('calculated_selling_price')->nullable()->default(0)->comment('Minor units');
            $table->timestamps();

            $table->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_costings');
        Schema::dropIfExists('product_packagings');
        Schema::dropIfExists('product_specifications');
        Schema::dropIfExists('products');
    }
};
