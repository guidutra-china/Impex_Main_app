<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite mais de uma PO do mesmo fornecedor na mesma PI (pedido dividido em
 * lotes, PO complementar). O fluxo "Gerar POs" continua reutilizando a PO
 * existente; a duplicidade só nasce por criação manual, que agora exibe aviso
 * no form, e o "Import from PI" exclui itens já presentes em qualquer PO da
 * PI. Mantém um índice composto não-único para as consultas por PI+fornecedor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique('po_pi_supplier_unique');
            $table->index(['proforma_invoice_id', 'supplier_company_id'], 'po_pi_supplier_index');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropIndex('po_pi_supplier_index');
            // Nota: falha se já existirem POs duplicadas por (PI, fornecedor).
            $table->unique(['proforma_invoice_id', 'supplier_company_id', 'deleted_at'], 'po_pi_supplier_unique');
        });
    }
};
