<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->string('container_number')->nullable()->after('shipment_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->dropColumn('container_number');
        });
    }
};
