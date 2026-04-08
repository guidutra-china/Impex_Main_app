<?php

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-off data repair: PI items imported from Supplier Quotations before
     * the currency-resolver wiring landed (Task 4) ended up with
     * cost_currency_code NULL or matching the PI currency even though the raw
     * unit_cost was in the supplier's (different) currency.
     *
     * Strategy:
     *   For each PI that has linked Supplier Quotations in a different
     *   currency than the PI, walk the items. If an item's
     *   cost_currency_code is NULL, try to infer the source currency from a
     *   linked SQ whose company_id matches the item's supplier_company_id.
     *   Fall back to the only-linked-SQ case.
     *
     *   Items where cost_currency_code is already set to a different value
     *   than the PI currency are assumed correct and left alone.
     */
    public function up(): void
    {
        $resolver = app(ProformaInvoiceItemCurrencyResolver::class);
        $repaired = 0;
        $skipped = 0;

        ProformaInvoice::with(['items', 'supplierQuotations'])->chunk(100, function ($pis) use ($resolver, &$repaired, &$skipped) {
            foreach ($pis as $pi) {
                $linkedSqs = $pi->supplierQuotations;

                if ($linkedSqs->isEmpty()) {
                    continue;
                }

                // Build a supplier_company_id => currency_code map from linked SQs.
                $currencyBySupplierId = $linkedSqs
                    ->filter(fn ($sq) => filled($sq->currency_code))
                    ->keyBy('company_id')
                    ->map(fn ($sq) => $sq->currency_code);

                $singleSqCurrency = $linkedSqs->count() === 1
                    ? $linkedSqs->first()->currency_code
                    : null;

                foreach ($pi->items as $item) {
                    if (filled($item->cost_currency_code) && $item->cost_currency_code !== $pi->currency_code) {
                        // Already has a non-trivial currency snapshot; leave it alone.
                        continue;
                    }

                    // Prefer match by supplier_company_id, fall back to
                    // single-SQ case.
                    $sourceCurrency = $currencyBySupplierId[$item->supplier_company_id] ?? $singleSqCurrency;

                    if (! $sourceCurrency || $sourceCurrency === $pi->currency_code) {
                        $skipped++;
                        continue;
                    }

                    $resolved = $resolver->resolve(
                        $sourceCurrency,
                        $pi->currency_code,
                        $pi->issue_date?->toDateString(),
                    );

                    // Write raw columns to avoid touching timestamps and to
                    // let the saving hook recompute unit_cost_in_document_currency.
                    $item->cost_currency_code = $resolved['currency'];
                    $item->cost_exchange_rate = $resolved['rate'];
                    $item->save();

                    $repaired++;
                }
            }
        });

        if ($repaired > 0 || $skipped > 0) {
            DB::statement("SELECT 'Repaired {$repaired} item(s); skipped {$skipped}' AS info");
        }
    }

    public function down(): void
    {
        // Data repair — no rollback. The previous NULL state is not worth
        // restoring and we don't track which rows were touched.
    }
};
