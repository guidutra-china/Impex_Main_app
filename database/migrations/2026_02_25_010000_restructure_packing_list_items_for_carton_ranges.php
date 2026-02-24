<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->dropColumn('carton_number');

            $table->integer('carton_from')->after('shipment_item_id');
            $table->integer('carton_to')->after('carton_from');
            $table->integer('qty_per_carton')->nullable()->after('quantity');
            $table->integer('total_quantity')->nullable()->after('qty_per_carton');

            $table->decimal('total_gross_weight', 12, 3)->nullable()->after('gross_weight');
            $table->decimal('total_net_weight', 12, 3)->nullable()->after('net_weight');
            $table->decimal('total_volume', 12, 4)->nullable()->after('volume');
        });
    }

    public function down(): void
    {
        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->string('carton_number')->after('shipment_id');

            $table->dropColumn([
                'carton_from',
                'carton_to',
                'qty_per_carton',
                'total_quantity',
                'total_gross_weight',
                'total_net_weight',
                'total_volume',
            ]);
        });
    }
};
