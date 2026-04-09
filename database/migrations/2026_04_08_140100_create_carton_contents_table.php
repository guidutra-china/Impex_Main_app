<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carton_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carton_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('pieces');
            $table->string('part_label', 100)->nullable();
            $table->char('multi_box_set_id', 26)->nullable();
            $table->decimal('weight_share', 10, 3)->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('multi_box_set_id');
            $table->index('shipment_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carton_contents');
    }
};
