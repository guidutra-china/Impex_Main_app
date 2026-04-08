<?php

namespace App\Domain\ProformaInvoices\Services;

use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Support\Facades\Log;

class ProformaInvoiceItemCurrencyResolver
{
    /**
     * Resolve the cost currency + FX rate for a PI item.
     *
     * @return array{currency: string, rate: float}
     */
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null
    ): array {
        $sourceCurrency = $sourceCurrency ?: $targetCurrency;

        if ($sourceCurrency === $targetCurrency) {
            return ['currency' => $targetCurrency, 'rate' => 1.0];
        }

        $source = Currency::findByCode($sourceCurrency);
        $target = Currency::findByCode($targetCurrency);

        if (! $source || ! $target) {
            Log::warning('PI cost currency resolver: unknown currency', [
                'source' => $sourceCurrency,
                'target' => $targetCurrency,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        $converted = ExchangeRate::convert($source->id, $target->id, 1.0, $date);

        if ($converted === null) {
            Log::warning('PI cost currency resolver: no FX rate available', [
                'source' => $sourceCurrency,
                'target' => $targetCurrency,
                'date' => $date,
            ]);

            return ['currency' => $sourceCurrency, 'rate' => 1.0];
        }

        return ['currency' => $sourceCurrency, 'rate' => (float) $converted];
    }
}
