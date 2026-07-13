<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Exceptions\CurrencyExchangeRateUnavailable;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use Illuminate\Support\Facades\DB;

class RefreshQuotationFxRatesAction
{
    public function __construct(
        private CurrencyExchangeResolver $fx,
    ) {}

    /**
     * Refresh the FX snapshot of every foreign-currency item (and its
     * supplier alternatives) with the current Exchange Rates table rate.
     * When $recalculatePrices is true, the client unit price is rebuilt
     * from the converted cost using the same formula as quotation
     * generation (embedded commission applied on top when set).
     *
     * @return array{updated: int, skipped: int}
     */
    public function execute(Quotation $quotation, bool $recalculatePrices = true, ?string $date = null): array
    {
        $date ??= today()->toDateString();

        return DB::transaction(function () use ($quotation, $recalculatePrices, $date) {
            $updated = 0;
            $skipped = 0;

            foreach ($quotation->items()->with('suppliers')->get() as $item) {
                foreach ($item->suppliers as $alternative) {
                    if (! $alternative->currency_code
                        || $alternative->currency_code === $quotation->currency_code) {
                        continue;
                    }

                    try {
                        $resolved = $this->fx->resolve(
                            $alternative->currency_code,
                            $quotation->currency_code,
                            $date,
                            strict: true,
                        );
                    } catch (CurrencyExchangeRateUnavailable) {
                        continue;
                    }

                    $alternative->update([
                        'cost_exchange_rate' => $resolved['rate'],
                        'cost_exchange_rate_captured_at' => $resolved['rate_date'] ?? $date,
                    ]);
                }

                if (! $item->cost_currency_code
                    || $item->cost_currency_code === $quotation->currency_code) {
                    continue;
                }

                try {
                    $resolved = $this->fx->resolve(
                        $item->cost_currency_code,
                        $quotation->currency_code,
                        $date,
                        strict: true,
                    );
                } catch (CurrencyExchangeRateUnavailable) {
                    $skipped++;

                    continue;
                }

                $payload = [
                    'cost_exchange_rate' => $resolved['rate'],
                    'cost_exchange_rate_captured_at' => $resolved['rate_date'] ?? $date,
                ];

                if ($recalculatePrices) {
                    $convertedCost = (int) round($item->unit_cost * $resolved['rate']);
                    $commissionRate = $quotation->commission_type === CommissionType::EMBEDDED
                        ? (float) $item->commission_rate
                        : 0.0;

                    $payload['unit_price'] = $commissionRate > 0
                        ? (int) round($convertedCost * (1 + $commissionRate / 100))
                        : $convertedCost;
                }

                $item->update($payload);
                $updated++;
            }

            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }
}
