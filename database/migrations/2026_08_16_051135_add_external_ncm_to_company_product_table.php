<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * NCM por cliente.
 *
 * NCM é a classificação fiscal do IMPORTADOR, não do produto: clientes
 * diferentes podem classificar a mesma peça de formas diferentes. Por isso vive
 * no pivot, junto do código/nome/descrição que o cliente usa — e não em
 * products.hs_code, que é global e guarda o HS (6 dígitos) da origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->string('external_ncm', 20)
                ->nullable()
                ->after('external_description')
                ->comment('Client-specific NCM (Brazilian tax classification)');
        });
    }

    public function down(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->dropColumn('external_ncm');
        });
    }
};
