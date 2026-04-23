<?php

namespace App\Domain\CRM\Reports\DTOs;

use App\Domain\CRM\Models\Company;
use Carbon\CarbonImmutable;

final class AccountsPayableReport
{
    /**
     * @param  list<array<string,mixed>>  $rows  Each row = one payment schedule installment
     * @param  list<array{currency:string,total:float,paid:float,balance:float}>  $totals  Per-currency totals
     */
    public function __construct(
        public readonly Company $company,
        public readonly CarbonImmutable $generatedAt,
        public readonly ?CarbonImmutable $periodFrom,
        public readonly ?CarbonImmutable $periodTo,
        public readonly bool $openOnly,
        public readonly string $locale,
        public readonly array $rows,
        public readonly array $totals,
    ) {}
}
