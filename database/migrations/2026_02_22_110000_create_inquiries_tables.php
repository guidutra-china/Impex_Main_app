<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status', 30)->default('received');
            $table->string('source', 20)->default('email');
            $table->string('currency_code', 3)->default('USD');
            $table->date('received_at')->nullable();
            $table->date('deadline')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
        });

        Schema::create('inquiry_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained('inquiries')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description')->nullable()->comment('Free-text description when product is not in catalog');
            $table->unsignedInteger('quantity')->default(1);
            $table->string('unit', 20)->default('pcs');
            $table->bigInteger('target_price')->nullable()->comment('Client target price in minor units, if provided');
            $table->text('specifications')->nullable()->comment('Client-provided specs or requirements');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('inquiry_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_items');
        Schema::dropIfExists('inquiries');
    }
};
