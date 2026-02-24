<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->bigInteger('custom_price')->nullable()->after('unit_price')
                ->comment('Override price for Commercial Invoice (minor units, scale 10000)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->dropColumn('custom_price');
        });
    }
};
