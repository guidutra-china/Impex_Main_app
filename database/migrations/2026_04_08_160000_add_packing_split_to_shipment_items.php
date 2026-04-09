<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            // Stores the split definition for items packed across multiple cartons
            // as distinct parts (e.g., Frame + Accessories). Shape:
            //   { "set_id": "01HXYZ...", "part_labels": ["Frame", "Accessories"] }
            // Null when the item is not split (normal packing).
            $table->json('packing_split')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_items', function (Blueprint $table) {
            $table->dropColumn('packing_split');
        });
    }
};
