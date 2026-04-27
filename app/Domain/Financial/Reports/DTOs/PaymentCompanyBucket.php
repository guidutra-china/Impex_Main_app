<?php

namespace App\Domain\Financial\Reports\DTOs;

use Carbon\CarbonImmutable;

final class PaymentCompanyBucket
{
    public function __construct(
        public readonly int $companyId,
        public readonly string $companyName,
        /** @var list<PaymentCurrencyTotal> */
        public readonly array $totalsByCurrency,
        public readonly int $paymentCount,
        public readonly ?CarbonImmutable $lastPaymentDate,
    ) {}
}
