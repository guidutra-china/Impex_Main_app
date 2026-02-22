<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->string('incoterm')->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->dropColumn('incoterm');
        });
    }
};
