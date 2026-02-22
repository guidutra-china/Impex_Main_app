<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->text('rfq_instructions')->nullable()->after('internal_notes');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->dropColumn('rfq_instructions');
        });
    }
};
