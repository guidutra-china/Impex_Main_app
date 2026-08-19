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
            // Ordem importa: em MySQL o índice explícito é o único servindo a FK
            // (dropar índice antes da FK falha com errno 1553); em SQLite >= 3.35
            // dropColumn vira um `alter table drop column` nativo, que recusa
            // dropar uma coluna ainda indexada. FK -> índice -> coluna funciona
            // nos dois drivers (dropForeign é no-op no SQLite).
            $table->dropForeign(['source_supplier_quotation_id']);
            $table->dropIndex(['source_supplier_quotation_id']);
            $table->dropColumn('source_supplier_quotation_id');
        });
    }
};
