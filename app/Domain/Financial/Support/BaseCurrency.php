<?php

namespace App\Domain\Financial\Support;

use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Support\Collection;

/**
 * Converts per-currency minor-unit totals into the base currency. Currencies
 * without a registered Currency row or exchange rate are returned in
 * `unconverted` so callers can render a warning instead of a wrong total.
 */
final class BaseCurrency
{
    /**
     * @param  Collection<string, int>  $byCurrency  currency code => minor units
     * @return array{total: int, unconverted: array<int, string>}
     */
    public static function convert(Collection $byCurrency): array
    {
        $base = Currency::base();

        if (! $base) {
            return ['total' => (int) $byCurrency->sum(), 'unconverted' => []];
        }

        $total = 0;
        $unconverted = [];

        foreach ($byCurrency as $code => $amount) {
            $currency = Currency::findByCode($code);

            if (! $currency) {
                $unconverted[] = $code;

                continue;
            }

            if ($currency->id === $base->id) {
                $total += (int) $amount;

                continue;
            }

            $converted = ExchangeRate::convert($currency->id, $base->id, (float) $amount);

            if ($converted === null) {
                $unconverted[] = $code;

                continue;
            }

            $total += (int) round($converted);
        }

        return ['total' => $total, 'unconverted' => $unconverted];
    }
}
