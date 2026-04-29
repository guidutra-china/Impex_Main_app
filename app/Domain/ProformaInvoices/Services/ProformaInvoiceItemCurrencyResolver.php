<?php

namespace App\Domain\ProformaInvoices\Services;

use App\Domain\Settings\Services\CurrencyExchangeResolver;

class ProformaInvoiceItemCurrencyResolver
{
    public function __construct(
        private CurrencyExchangeResolver $resolver = new CurrencyExchangeResolver,
    ) {}

    /**
     * Resolve the cost currency + FX rate for a PI item.
     *
     * @return array{currency: string, rate: float, rate_date: ?string}
     */
    public function resolve(
        ?string $sourceCurrency,
        string $targetCurrency,
        ?string $date = null,
    ): array {
        return $this->resolver->resolve($sourceCurrency, $targetCurrency, $date, strict: false);
    }
}
