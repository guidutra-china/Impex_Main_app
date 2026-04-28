<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_item_suppliers', function (Blueprint $table) {
            $table->decimal('cost_exchange_rate', 18, 8)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_item_suppliers', function (Blueprint $table) {
            $table->dropColumn('cost_exchange_rate');
        });
    }
};
