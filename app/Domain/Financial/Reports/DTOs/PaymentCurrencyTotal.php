<?php

namespace App\Domain\Financial\Reports\DTOs;

final class PaymentCurrencyTotal
{
    public function __construct(
        public readonly string $currency,
        public readonly int $total,
        public readonly int $count,
    ) {}
}
