<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a persistent reference_code column to the products table.
     *
     * This field stores the user-supplied reference code from the Excel import
     * template (column "Reference Code"). It is used as the primary matching key
     * when re-importing products, preventing duplicates even if the product name
     * is later edited. Falls back to name-based matching when empty.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('reference_code')->nullable()->after('sku');
            $table->index('reference_code');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['reference_code']);
            $table->dropColumn('reference_code');
        });
    }
};
