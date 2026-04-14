<?php

namespace App\Domain\CRM\Reports\FinancialSectionBuilders;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use App\Domain\CRM\Reports\DTOs\StatementSection;

interface FinancialSectionBuilder
{
    public function key(): string;

    public function build(Company $company, FinancialReportFilters $filters): StatementSection;
}
