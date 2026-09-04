<?php

use App\Domain\Quotations\Support\QuotationItemUnitBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A unidade de medida existia em inquiry_items, supplier_quotation_items
     * e proforma_invoice_items, mas não em quotation_items — a Quotation, no
     * meio do fluxo, descartava o valor e o PDF ao cliente e a PI gerada
     * saíam com "pcs" fixo (QT-2026-00031: vidro cotado em SQM impresso
     * como peça).
     *
     * O backfill é derivado e determinístico, por isso roda aqui e não num
     * comando manual. Regra e SQL em QuotationItemUnitBackfill.
     */
    public function up(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('unit', 20)->default('pcs')->after('quantity');
        });

        QuotationItemUnitBackfill::run();
    }

    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
