<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferência de nomenclatura de produtos nos documentos gerados, por empresa.
 *
 * As quatro colunas são anuláveis e SEM default: NULL significa "não
 * configurado — herdar da matriz (parent_company_id)" e, se a matriz também
 * estiver em branco, cair no comportamento histórico do sistema ('counterparty'
 * em tudo, com descrição visível). É a única forma de distinguir "empresa
 * escolheu counterparty de propósito" de "ninguém mexeu nisso ainda"; um
 * default NOT NULL apagaria essa diferença e faria uma filial ignorar uma
 * matriz configurada explicitamente sem dar nenhum sinal.
 *
 * String em vez de enum nativo do MySQL: é a convenção do repositório
 * (companies.status é string + cast de enum) e evita ALTER TABLE ... MODIFY
 * para adicionar um terceiro valor no futuro. O enum nativo também degrada
 * para varchar no SQLite, então o teste nunca pegaria a diferença. O cast do
 * model já garante o conjunto de valores onde importa.
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
            $table->string('document_code_source', 20)
                ->nullable()
                ->after('preferred_language');
            $table->string('document_name_source', 20)
                ->nullable()
                ->after('document_code_source');
            $table->string('document_description_source', 20)
                ->nullable()
                ->after('document_name_source');
            $table->boolean('document_show_description')
                ->nullable()
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
