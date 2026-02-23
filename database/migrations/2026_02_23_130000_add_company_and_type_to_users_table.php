<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('type', 20)->default('internal')->after('email');
            $table->foreignId('company_id')->nullable()->after('type')
                ->constrained('companies')->nullOnDelete();
            $table->string('phone', 30)->nullable()->after('name');
            $table->string('job_title', 100)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn(['type', 'company_id', 'phone', 'job_title']);
        });
    }
};
