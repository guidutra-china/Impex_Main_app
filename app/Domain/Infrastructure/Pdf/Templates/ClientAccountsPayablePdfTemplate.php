<?php

namespace App\Domain\Infrastructure\Pdf\Templates;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\AccountsPayableReport;
use App\Domain\Infrastructure\Models\Document;
use Illuminate\Support\Str;

class ClientAccountsPayablePdfTemplate extends AbstractPdfTemplate
{
    public const DOCUMENT_TYPE = 'client_accounts_payable_pdf';

    public function getView(): string
    {
        return 'pdf.client-accounts-payable';
    }

    public function getDocumentTitle(): string
    {
        return 'Accounts Payable';
    }

    public function getDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function getOrientation(): string
    {
        return 'portrait';
    }

    /**
     * Bypass Company::documents() legacy relation; query the polymorphic
     * Document table directly for versioning of this document type.
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

        return "accounts-payable-{$slug}-v{$this->getNextVersion()}.pdf";
    }

    protected function getDocumentData(): array
    {
        $report = $this->options['report'] ?? null;

        if (! $report instanceof AccountsPayableReport) {
            throw new \InvalidArgumentException(
                'ClientAccountsPayablePdfTemplate requires an AccountsPayableReport instance in options["report"].'
            );
        }

        return ['report' => $report];
    }
}
