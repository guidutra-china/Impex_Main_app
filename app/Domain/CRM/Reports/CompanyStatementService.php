<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\StatementFilters;
use App\Domain\CRM\Reports\DTOs\StatementReport;
use Carbon\CarbonImmutable;

final class CompanyStatementService
{
    public function __construct(
        private readonly StatementSectionResolver $resolver,
        private readonly FinancialSummaryBuilder $financialBuilder,
    ) {
    }

    public function build(Company $company, StatementFilters $filters): StatementReport
    {
        $role = $this->resolvePrimaryRole($company);
        $builders = $this->resolver->resolve($role);

        $sections = [];
        foreach ($builders as $builder) {
            if (! $filters->includes($builder->key())) {
                continue;
            }
            $sections[] = $builder->build($company, $filters);
        }

        $financial = $this->financialBuilder->build($company, $role, $filters);

        return new StatementReport(
            company: $company,
            periodFrom: $filters->from,
            periodTo: $filters->to,
            generatedAt: CarbonImmutable::now(),
            locale: $filters->locale,
            financialSummary: $financial,
            sections: $sections,
        );
    }

    private function resolvePrimaryRole(Company $company): CompanyRole
    {
        $roleValues = $company->companyRoles()->pluck('role')->map(
            fn ($r) => $r instanceof CompanyRole ? $r->value : (string) $r,
        )->all();

        foreach ([CompanyRole::CLIENT, CompanyRole::SUPPLIER, CompanyRole::FORWARDER] as $role) {
            if (in_array($role->value, $roleValues, true)) {
                return $role;
            }
        }

        return CompanyRole::CLIENT;
    }
}
