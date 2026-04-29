<?php

namespace App\Domain\Settings\Services;

use App\Domain\Settings\Exceptions\CurrencyExchangeRateUnavailable;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;

class CurrencyExchangeResolver
{
    /**
     * Resolve the cost currency + FX rate for an item.
     *
     * The optional 'rate_date' is the date of the matching ExchangeRate
     * record (when available), so callers can persist the captured-at
     * date alongside the rate. Null when same-currency or no rate found.
     *
     * @return array{currency: string, rate: float, rate_date: ?string}
     */
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null,
        bool $strict = false,
    ): array {
        $sourceCurrency = $sourceCurrency ?: $targetCurrency;

        if ($sourceCurrency === $targetCurrency) {
            return ['currency' => $targetCurrency, 'rate' => 1.0, 'rate_date' => null];
        }

        $source = Currency::findByCode($sourceCurrency);
        $target = Currency::findByCode($targetCurrency);

        if (! $source || ! $target) {
            if ($strict) {
                throw new CurrencyExchangeRateUnavailable($sourceCurrency, $targetCurrency, $date);
            }
            Log::warning('Currency exchange resolver: unknown currency', [
                'source' => $sourceCurrency, 'target' => $targetCurrency,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0, 'rate_date' => null];
        }

        $converted = ExchangeRate::convert($source->id, $target->id, 1.0, $date);

        if ($converted === null) {
            if ($strict) {
                throw new CurrencyExchangeRateUnavailable($sourceCurrency, $targetCurrency, $date);
            }
            Log::warning('Currency exchange resolver: no FX rate available', [
                'source' => $sourceCurrency, 'target' => $targetCurrency, 'date' => $date,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0, 'rate_date' => null];
        }

        return [
            'currency' => $sourceCurrency,
            'rate' => (float) $converted,
            'rate_date' => $this->resolveRateDate($source, $target, $date),
        ];
    }

    /**
     * Find the effective `exchange_rates.date` backing the conversion.
     * For triangulation, returns the older of the two source/target lookups
     * (the conservative "as of" — every component is at least this fresh).
     */
    protected function resolveRateDate(Currency $source, Currency $target, ?string $date): ?string
    {
        $base = Currency::base();
        if (! $base) {
            return null;
        }

        $dates = [];

        if ($source->id !== $base->id) {
            $rate = ExchangeRate::getLatestRate($base->id, $source->id, $date);
            if ($rate) {
                $dates[] = $rate->date->toDateString();
            }
        }

        if ($target->id !== $base->id) {
            $rate = ExchangeRate::getLatestRate($base->id, $target->id, $date);
            if ($rate) {
                $dates[] = $rate->date->toDateString();
            }
        }

        if (empty($dates)) {
            return null;
        }

        sort($dates);

        return $dates[0];
    }
}
