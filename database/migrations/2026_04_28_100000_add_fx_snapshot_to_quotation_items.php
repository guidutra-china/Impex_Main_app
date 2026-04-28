<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('cost_currency_code', 3)->nullable()->after('unit_cost');
            $table->decimal('cost_exchange_rate', 18, 8)->nullable()->after('cost_currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn(['cost_currency_code', 'cost_exchange_rate']);
        });
    }
};
