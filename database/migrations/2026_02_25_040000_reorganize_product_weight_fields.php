<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_packagings', function (Blueprint $table) {
            $table->decimal('carton_net_weight', 10, 3)->nullable()->after('carton_weight');
        });

        Schema::table('product_specifications', function (Blueprint $table) {
            $table->dropColumn('gross_weight');
        });
    }

    public function down(): void
    {
        Schema::table('product_packagings', function (Blueprint $table) {
            $table->dropColumn('carton_net_weight');
        });

        Schema::table('product_specifications', function (Blueprint $table) {
            $table->decimal('gross_weight', 10, 3)->nullable()->after('net_weight');
        });
    }
};
