<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->string('cost_currency_code', 3)->nullable()->after('unit_cost');
            $table->decimal('cost_exchange_rate', 18, 8)->default(1)->after('cost_currency_code');
            $table->bigInteger('unit_cost_in_document_currency')->default(0)->after('cost_exchange_rate');
        });

        // Backfill: legacy items are assumed to already be in the PI's currency.
        // Preload the PI currency map once to avoid N+1 lookups.
        $currencyByPi = DB::table('proforma_invoices')->pluck('currency_code', 'id');

        // chunkById is safe here: we update non-id columns only, so pagination cursor is stable.
        DB::table('proforma_invoice_items')
            ->whereNull('cost_currency_code')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($currencyByPi) {
                foreach ($rows as $row) {
                    DB::table('proforma_invoice_items')
                        ->where('id', $row->id)
                        ->update([
                            'cost_currency_code' => $currencyByPi[$row->proforma_invoice_id] ?? null,
                            'cost_exchange_rate' => 1,
                            'unit_cost_in_document_currency' => $row->unit_cost,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('proforma_invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'cost_currency_code',
                'cost_exchange_rate',
                'unit_cost_in_document_currency',
            ]);
        });
    }
};
