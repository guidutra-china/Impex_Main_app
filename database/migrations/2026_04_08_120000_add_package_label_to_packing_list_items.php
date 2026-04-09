<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->string('package_label', 100)->nullable()->after('description');
            $table->boolean('is_primary_package')->default(true)->after('package_label');
        });
    }

    public function down(): void
    {
        Schema::table('packing_list_items', function (Blueprint $table) {
            $table->dropColumn(['package_label', 'is_primary_package']);
        });
    }
};
