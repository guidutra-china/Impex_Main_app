<?php

namespace App\Domain\Infrastructure\Excel\Templates;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\AccountsPayableReport;
use Illuminate\Support\Str;

class ClientAccountsPayableExcelTemplate extends AbstractExcelTemplate
{
    public const DOCUMENT_TYPE = 'client_accounts_payable_xlsx';

    public function getDocumentTitle(): string
    {
        /** @var Company $company */
        $company = $this->model;

        return __('client_accounts_payable.title', [], $this->locale()).' — '.$company->name;
    }

    public function getDocumentType(): string
    {
        return self::DOCUMENT_TYPE;
    }

    public function getFilename(): string
    {
        /** @var Company $company */
        $company = $this->model;
        $slug = Str::slug($company->name ?? 'company-'.$company->getKey());
        $date = now()->format('Y-m-d');

        return "accounts-payable-{$slug}-{$date}.xlsx";
    }

    protected function getHeaders(): array
    {
        $locale = $this->locale();

        return [
            __('client_accounts_payable.columns.document', [], $locale),
            __('client_accounts_payable.columns.client_reference', [], $locale),
            __('client_accounts_payable.columns.installment', [], $locale),
            __('client_accounts_payable.columns.due_date', [], $locale),
            __('client_accounts_payable.columns.amount', [], $locale),
            __('client_accounts_payable.columns.paid', [], $locale),
            __('client_accounts_payable.columns.balance', [], $locale),
            __('client_accounts_payable.columns.currency', [], $locale),
            __('client_accounts_payable.columns.status', [], $locale),
        ];
    }

    protected function getRows(): array
    {
        $report = $this->report();
        $rows = [];

        foreach ($report->rows as $row) {
            $rows[] = [
                $row['document'] ?? '',
                $row['client_reference'] ?? '',
                $row['installment'] ?? '',
                $row['due_date'] ?? '',
                (float) ($row['amount'] ?? 0),
                (float) ($row['paid'] ?? 0),
                (float) ($row['balance'] ?? 0),
                $row['currency'] ?? '',
                $row['status'] ?? '',
            ];
        }

        // Append totals-by-currency footer with a blank separator
        if (! empty($report->totals)) {
            $locale = $this->locale();
            $rows[] = ['', '', '', '', '', '', '', '', ''];
            $rows[] = [
                __('client_accounts_payable.totals_by_currency', [], $locale),
                '', '', '', '', '', '', '', '',
            ];
            foreach ($report->totals as $t) {
                $rows[] = [
                    '',
                    '',
                    '',
                    '',
                    (float) $t['total'],
                    (float) $t['paid'],
                    (float) $t['balance'],
                    $t['currency'],
                    '',
                ];
            }
        }

        return $rows;
    }

    private function report(): AccountsPayableReport
    {
        $report = $this->options['report'] ?? null;

        if (! $report instanceof AccountsPayableReport) {
            throw new \InvalidArgumentException(
                'ClientAccountsPayableExcelTemplate requires an AccountsPayableReport in options["report"].'
            );
        }

        return $report;
    }

    private function locale(): string
    {
        return (string) ($this->options['locale'] ?? app()->getLocale());
    }
}
