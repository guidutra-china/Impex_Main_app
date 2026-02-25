<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_product_id')->constrained('company_product')->cascadeOnDelete();
            $table->string('category');
            $table->string('title');
            $table->string('disk')->default('public');
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_product_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_product_documents');
    }
};
