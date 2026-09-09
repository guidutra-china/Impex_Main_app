<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Viagem de cliente pode apontar para UMA PI do mesmo cliente — rastreio,
     * não cobrança (a cobrança segue pela nota de débito, que passa a
     * carregar a PI também). Uma viagem cobre uma PI só, por decisão de
     * 2026-09-09; se um dia cobrir várias, vira pivot.
     */
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('proforma_invoice_id')
                ->nullable()
                ->after('company_id')
                ->constrained('proforma_invoices')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proforma_invoice_id');
        });
    }
};
