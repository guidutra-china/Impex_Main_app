<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedule_components', function (Blueprint $table) {
            $table->unsignedInteger('quantity_required')->default(0)->after('supplier_name');
        });
    }

    public function down(): void
    {
        Schema::table('production_schedule_components', function (Blueprint $table) {
            $table->dropColumn('quantity_required');
        });
    }
};
