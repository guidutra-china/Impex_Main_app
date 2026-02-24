<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_packagings', function (Blueprint $table) {
            $table->string('packaging_type', 20)->default('carton')->after('product_id');
        });

        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->string('packaging_type', 20)->default('carton')->after('shipment_item_id');
            $table->unsignedInteger('pallet_number')->nullable()->after('packaging_type');
        });
    }

    public function down(): void
    {
        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->dropColumn(['packaging_type', 'pallet_number']);
        });

        Schema::table('product_packagings', function (Blueprint $table) {
            $table->dropColumn('packaging_type');
        });
    }
};
