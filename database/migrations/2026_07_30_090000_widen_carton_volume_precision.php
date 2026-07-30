<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CBM por caixa era gravado com 4 casas decimais — 60×29×21 cm = 0,036540 m³
 * virava 0,0365 e, somado sobre milhares de caixas, o total divergia do real
 * (SH-2026-00029: 35,77 vs 35,81). Com 6 casas o CBM de dimensões em cm com
 * 2 decimais fica exato na prática. Os valores existentes são recomputados a
 * partir das dimensões.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartons', function (Blueprint $table) {
            $table->decimal('volume', 12, 6)->nullable()->change();
        });

        Schema::table('product_packagings', function (Blueprint $table) {
            $table->decimal('carton_cbm', 12, 6)->nullable()->change();
        });

        DB::statement(
            'UPDATE cartons SET volume = ROUND(length * width * height / 1000000, 6)
             WHERE length > 0 AND width > 0 AND height > 0'
        );

        DB::statement(
            'UPDATE product_packagings SET carton_cbm = ROUND(carton_length * carton_width * carton_height / 1000000, 6)
             WHERE carton_length > 0 AND carton_width > 0 AND carton_height > 0'
        );
    }

    public function down(): void
    {
        Schema::table('cartons', function (Blueprint $table) {
            $table->decimal('volume', 10, 4)->nullable()->change();
        });

        Schema::table('product_packagings', function (Blueprint $table) {
            $table->decimal('carton_cbm', 10, 4)->nullable()->change();
        });
    }
};
