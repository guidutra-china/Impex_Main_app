<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->boolean('is_credit')->default(false)->after('is_blocking')
                ->comment('True for supplier credits/deductions');
            $table->nullableMorphs('source');
        });
    }

    public function down(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->dropColumn('is_credit');
            $table->dropMorphs('source');
        });
    }
};
