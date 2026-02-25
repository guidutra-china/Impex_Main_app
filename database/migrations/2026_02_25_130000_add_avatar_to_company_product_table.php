<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('is_preferred');
            $table->string('avatar_disk')->default('public')->after('avatar_path');
        });
    }

    public function down(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'avatar_disk']);
        });
    }
};
