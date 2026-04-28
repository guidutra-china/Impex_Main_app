<?php

namespace App\Domain\Settings\Exceptions;

use RuntimeException;

class CurrencyExchangeRateUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $sourceCurrency,
        public readonly string $targetCurrency,
        public readonly ?string $date = null,
    ) {
        parent::__construct(sprintf(
            'No approved exchange rate found for %s → %s on or before %s.',
            $sourceCurrency,
            $targetCurrency,
            $date ?? today()->toDateString(),
        ));
    }
}
