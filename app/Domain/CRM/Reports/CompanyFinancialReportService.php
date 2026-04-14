<?php

namespace App\Domain\CRM\Reports;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportData;
use App\Domain\CRM\Reports\DTOs\FinancialReportFilters;
use Carbon\CarbonImmutable;

final class CompanyFinancialReportService
{
    public function __construct(
        private readonly FinancialReportSectionResolver $resolver,
        private readonly FinancialSummaryBuilder $financialBuilder,
    ) {
    }

    public function build(Company $company, FinancialReportFilters $filters): FinancialReportData
    {
        $role = $this->resolvePrimaryRole($company);
        $builders = $this->resolver->resolve($role, $filters->context);

        $sections = [];
        foreach ($builders as $builder) {
            if (! $filters->includes($builder->key())) {
                continue;
            }
            $sections[] = $builder->build($company, $filters);
        }

        // Build financial summary using existing builder (reuse StatementFilters temporarily)
        $statementFilters = new DTOs\StatementFilters(
            from: $filters->from,
            to: $filters->to,
            statusScope: $filters->statusScope,
            sectionKeys: $filters->sectionKeys,
            currency: $filters->currency,
            locale: $filters->locale,
        );
        $financial = $this->financialBuilder->build($company, $role, $statementFilters);

        return new FinancialReportData(
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
