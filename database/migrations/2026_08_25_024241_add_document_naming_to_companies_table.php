<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência de nomenclatura de produtos nos documentos gerados, por empresa.
 *
 * Defaults reproduzem o comportamento histórico ('counterparty' em tudo, com
 * descrição visível) para nenhum documento existente mudar até alguém trocar
 * explicitamente uma dessas configurações.
 *
 * Um único conjunto de colunas serve tanto para papel de cliente quanto de
 * fornecedor: em 2026-08-24 nenhuma empresa acumulava os dois papéis ao mesmo
 * tempo. Se isso mudar, separar por papel é uma migração aditiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->enum('document_code_source', ['counterparty', 'system'])
                ->default('counterparty')
                ->after('preferred_language');
            $table->enum('document_name_source', ['counterparty', 'system'])
                ->default('counterparty')
                ->after('document_code_source');
            $table->enum('document_description_source', ['counterparty', 'system'])
                ->default('counterparty')
                ->after('document_name_source');
            $table->boolean('document_show_description')
                ->default(true)
                ->after('document_description_source');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'document_code_source',
                'document_name_source',
                'document_description_source',
                'document_show_description',
            ]);
        });
    }
};
