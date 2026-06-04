<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_expense_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_expense_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('public');
            $table->string('path');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index(['trip_expense_id', 'sort_order']);
            $table->index(['trip_expense_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_expense_photos');
    }
};
