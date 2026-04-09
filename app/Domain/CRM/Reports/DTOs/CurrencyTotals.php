<?php

namespace App\Domain\CRM\Reports\DTOs;

final class CurrencyTotals
{
    public function __construct(
        public readonly string $currency,
        public readonly float $invoiced,
        public readonly float $paid,
        public readonly float $open,
    ) {
    }
}
