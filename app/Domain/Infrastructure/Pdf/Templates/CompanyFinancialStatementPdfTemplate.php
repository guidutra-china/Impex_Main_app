<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportData;
use App\Domain\Infrastructure\Models\Document;
use Illuminate\Support\Str;

class CompanyFinancialStatementPdfTemplate extends AbstractPdfTemplate
{
    public const DOCUMENT_TYPE = 'company_financial_statement_pdf';

    public function getView(): string
    {
        return 'pdf.financial-report';
    }

    public function getDocumentTitle(): string
    {
        return 'Financial Statement';
    }

    public function getDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function getOrientation(): string
    {
        return 'landscape';
    }

    /**
     * Company::documents() points to the legacy CompanyDocument relation, so
     * versioning here queries the polymorphic Document table directly.
     */
    public function getNextVersion(): int
    {
        /** @var Company $company */
        $company = $this->model;

        $currentMax = Document::query()
            ->where('documentable_type', $company->getMorphClass())
            ->where('documentable_id', $company->getKey())
            ->where('type', $this->getDocumentType())
            ->max('version') ?? 0;

        return $currentMax + 1;
    }

    public function getFilename(): string
    {
        /** @var Company $company */
        $company = $this->model;

        $slug = Str::slug($company->name ?? 'company-'.$company->getKey());

        return "financial-statement-{$slug}-v{$this->getNextVersion()}.pdf";
    }

    protected function getDocumentData(): array
    {
        $report = $this->options['report'] ?? null;

        if (! $report instanceof FinancialReportData) {
            throw new \InvalidArgumentException(
                'CompanyFinancialStatementPdfTemplate requires a FinancialReportData instance passed via options["report"].'
            );
        }

        return ['report' => $report];
    }
}
