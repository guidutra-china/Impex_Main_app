<?php

use App\Domain\Infrastructure\Models\Document;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * documents.type nasceu varchar(30) e o maior tipo em uso já ocupava 29
 * caracteres (shipment_proforma_invoice_pdf). O extrato financeiro do embarque
 * — shipment_financial_statement_pdf, 32 — estourou a coluna e o insert falhou
 * em produção com "Data too long for column 'type'".
 *
 * Os testes rodam em SQLite, que não aplica o limite de varchar, então nada
 * pegou isso antes do deploy. Além desta migração, existe agora um teste que
 * confere o tipo de todo template de PDF contra o tamanho real da coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('type', Document::TYPE_MAX_LENGTH)
                ->comment('e.g. proforma_invoice, commercial_invoice, packing_list, shipment_financial_statement_pdf')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('type', 30)->change();
        });
    }
};
