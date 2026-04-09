<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartons', function (Blueprint $table) {
            $table->foreignId('shipment_container_id')->nullable()->after('shipment_id')->constrained('shipment_containers')->nullOnDelete();
            $table->foreignId('shipment_pallet_id')->nullable()->after('shipment_container_id')->constrained('shipment_pallets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cartons', function (Blueprint $table) {
            $table->dropForeign(['shipment_pallet_id']);
            $table->dropForeign(['shipment_container_id']);
            $table->dropColumn(['shipment_pallet_id', 'shipment_container_id']);
        });
    }
};
