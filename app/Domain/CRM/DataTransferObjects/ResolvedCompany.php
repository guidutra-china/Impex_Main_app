<?php

namespace App\Domain\CRM\DataTransferObjects;

use App\Domain\CRM\Models\Company;

final readonly class ResolvedCompany
{
    public function __construct(
        public Company $company,
        public bool $wasCreated,
    ) {}
}
