<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_expenses', function (Blueprint $table) {
            $table->foreignId('recurring_source_id')
                ->nullable()
                ->after('recurring_day')
                ->constrained('company_expenses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_source_id');
        });
    }
};
