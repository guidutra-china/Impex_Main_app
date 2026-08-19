<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            // Bank fees are entered on the payment that incurred them; this
            // link makes the payment the single source of truth for the cost.
            $table->foreignId('source_payment_id')
                ->nullable()
                ->after('created_by')
                ->constrained('payments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            $table->dropForeign(['source_payment_id']);
            $table->dropColumn('source_payment_id');
        });
    }
};
