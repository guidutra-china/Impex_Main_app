<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->text('external_description')->nullable()
                ->after('external_name')
                ->comment('Company-specific product description for invoices');
        });
    }

    public function down(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->dropColumn('external_description');
        });
    }
};
