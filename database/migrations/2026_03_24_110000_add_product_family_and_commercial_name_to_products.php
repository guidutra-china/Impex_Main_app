<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('commercial_name')->nullable()->after('name');
            $table->string('product_family')->nullable()->after('commercial_name');

            $table->index('product_family');
            $table->index('commercial_name');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['product_family']);
            $table->dropIndex(['commercial_name']);
            $table->dropColumn(['commercial_name', 'product_family']);
        });
    }
};
