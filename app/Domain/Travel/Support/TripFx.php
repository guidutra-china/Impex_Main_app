<?php

namespace App\Domain\Travel\Support;

use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\Travel\Models\Trip;

/**
 * Currency helpers for trip billing/reporting: indicative FX rate lookup and
 * minor-unit conversion. Rates are indicative (latest approved) and meant to be
 * overridable by the user before a debit note / report is produced.
 */
class TripFx
{
    /**
     * Indicative rate: 1 unit of $from = N units of $to. Falls back to 1.0 when
     * the currencies are equal or no approved rate is available.
     */
    public static function indicativeRate(string $from, string $to): float
    {
        if ($from === $to) {
            return 1.0;
        }

        $fromCurrency = Currency::findByCode($from);
        $toCurrency = Currency::findByCode($to);

        if ($fromCurrency === null || $toCurrency === null) {
            return 1.0;
        }

        $converted = ExchangeRate::convert($fromCurrency->id, $toCurrency->id, 1.0);

        return $converted !== null ? (float) $converted : 1.0;
    }

    /**
     * Convert an amount in minor units by an FX multiplier, returning minor units
     * of the target currency. The scale cancels out, so amountMinor * rate works.
     */
    public static function convertMinor(int $amountMinor, float $rate): int
    {
        return (int) round($amountMinor * $rate);
    }

    /**
     * Distinct expense currencies on a trip, in stable order.
     *
     * @return array<int, string>
     */
    public static function distinctCurrencies(Trip $trip): array
    {
        return $trip->expenses
            ->pluck('currency_code')
            ->unique()
            ->values()
            ->all();
    }
}
