<?php

namespace App\Domain\CRM\Reports\DTOs;

final class AgingBuckets
{
    public function __construct(
        public readonly string $currency,
        public readonly float $bucket0to30,
        public readonly float $bucket31to60,
        public readonly float $bucket61to90,
        public readonly float $bucket90plus,
    ) {
    }
}
