<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->dropColumn('payment_terms');
            $table->foreignId('payment_term_id')
                ->nullable()
                ->after('incoterm')
                ->constrained('payment_terms')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_term_id');
            $table->string('payment_terms', 255)->nullable()->after('incoterm');
        });
    }
};
