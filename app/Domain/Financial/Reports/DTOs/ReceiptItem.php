<?php

namespace App\Domain\Financial\Reports\DTOs;

use Carbon\CarbonImmutable;

final readonly class ReceiptItem
{
    public function __construct(
        public CarbonImmutable $paymentDate,
        public string $paymentReference,
        public ?string $stageLabel,
        public int $amountOriginal,
        public ?int $amountPresentation,
        public ?float $exchangeRateToPresentation,
        public string $paymentUrl,
    ) {
    }
}
