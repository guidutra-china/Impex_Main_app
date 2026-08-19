<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('source_supplier_quotation_id')
                ->nullable()
                ->after('company_id')
                ->constrained('supplier_quotations')
                ->nullOnDelete();

            $table->index('source_supplier_quotation_id');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_supplier_quotation_id');
        });
    }
};
