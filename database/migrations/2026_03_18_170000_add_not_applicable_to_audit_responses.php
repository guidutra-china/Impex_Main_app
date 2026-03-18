<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_responses', function (Blueprint $table) {
            $table->boolean('is_not_applicable')->default(false)->after('passed');
        });
    }

    public function down(): void
    {
        Schema::table('audit_responses', function (Blueprint $table) {
            $table->dropColumn('is_not_applicable');
        });
    }
};
