<?php

namespace App\Domain\CRM\Reports\SectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;

interface SectionBuilder
{
    public function key(): string;

    public function build(Company $company, StatementFilters $filters): StatementSection;
}
