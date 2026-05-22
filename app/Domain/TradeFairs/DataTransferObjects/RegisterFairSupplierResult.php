<?php

namespace App\Domain\TradeFairs\DataTransferObjects;

use App\Domain\CRM\Models\Company;

final class RegisterFairSupplierResult
{
    /**
     * @param  array<int, string>  $productNames
     */
    public function __construct(
        public readonly Company $company,
        public readonly array $productNames,
        public readonly bool $companyReused,
    ) {}
}
