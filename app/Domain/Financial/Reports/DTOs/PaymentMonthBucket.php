<?php

namespace App\Domain\Financial\Reports\DTOs;

final class PaymentMonthBucket
{
    public function __construct(
        public readonly string $yearMonth, // YYYY-MM
        public readonly string $label,     // pt-BR localized label
        /** @var list<PaymentCurrencyTotal> */
        public readonly array $totalsByCurrency,
        public readonly int $paymentCount,
    ) {}
}
