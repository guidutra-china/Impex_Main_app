<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_schedule_entries', function (Blueprint $table) {
            $table->integer('actual_quantity')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('production_schedule_entries', function (Blueprint $table) {
            $table->dropColumn('actual_quantity');
        });
    }
};
