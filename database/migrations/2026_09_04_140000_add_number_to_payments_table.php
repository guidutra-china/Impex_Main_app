<?php

use App\Domain\Financial\Support\PaymentNumberBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagamento era o único documento sem numeração: `reference` é a
     * referência bancária (SWIFT), livre e vazia em 9 de cada 10 linhas, e os
     * relatórios a imprimiam como se fosse o número. `number` nasce via
     * reference_sequences (PAY-YYYY-NNNNNN); o legado é numerado aqui, na
     * ordem de criação, por PaymentNumberBackfill.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->after('id');
        });

        PaymentNumberBackfill::run();

        Schema::table('payments', function (Blueprint $table) {
            $table->unique('number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
