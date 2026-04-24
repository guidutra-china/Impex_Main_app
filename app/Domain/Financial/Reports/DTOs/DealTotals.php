<?php

namespace App\Domain\Financial\Reports\DTOs;

final readonly class DealTotals
{
    public function __construct(
        public int $cashBalance,
        public int $margin,
        public float $marginPct,
    ) {
    }
}
