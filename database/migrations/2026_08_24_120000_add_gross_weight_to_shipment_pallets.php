<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peso bruto do pallet montado (caixas + estrado), como saiu da balança.
     * Quando preenchido, manda nos totais no lugar da soma das caixas.
     */
    public function up(): void
    {
        Schema::table('shipment_pallets', function (Blueprint $table) {
            $table->decimal('gross_weight', 10, 3)->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_pallets', function (Blueprint $table) {
            $table->dropColumn('gross_weight');
        });
    }
};
