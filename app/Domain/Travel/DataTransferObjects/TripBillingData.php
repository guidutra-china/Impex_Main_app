<?php

namespace App\Domain\Travel\DataTransferObjects;

use App\Domain\Settings\Models\Currency;
use App\Domain\Travel\Models\Trip;
use App\Domain\Travel\Support\TripFx;

/**
 * The billing decision for a client/supplier trip's debit note: which currency
 * to bill in, plus the (editable) FX rate from each expense currency into it.
 */
class TripBillingData
{
    /**
     * @param  array<string, float>  $rates  expense currency code => rate to billing currency
     */
    public function __construct(
        public readonly string $billingCurrency,
        public readonly array $rates = [],
    ) {}

    public function rateFor(string $currency): float
    {
        if ($currency === $this->billingCurrency) {
            return 1.0;
        }

        return (float) ($this->rates[$currency] ?? 1.0);
    }

    /**
     * Default billing: the base currency (or USD) with indicative rates from
     * each of the trip's expense currencies. Used when no explicit choice is
     * supplied (e.g. programmatic approval / tests).
     */
    public static function for(Trip $trip): self
    {
        $billing = Currency::base()?->code ?? 'USD';

        $rates = [];
        foreach (TripFx::distinctCurrencies($trip) as $currency) {
            $rates[$currency] = TripFx::indicativeRate($currency, $billing);
        }

        return new self($billing, $rates);
    }
}
