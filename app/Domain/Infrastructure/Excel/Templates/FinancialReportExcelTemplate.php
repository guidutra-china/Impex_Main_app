<?php

namespace App\Domain\Infrastructure\Excel\Templates;

use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Reports\DTOs\FinancialReportData;
use App\Domain\CRM\Reports\DTOs\StatementSection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class FinancialReportExcelTemplate extends AbstractExcelTemplate
{
    public const DOCUMENT_TYPE = 'financial_report_xlsx';

    /**
     * Money columns mirror the PDF Blade list (resources/views/pdf/financial-report.blade.php).
     * Kept in sync manually — keep this list aligned when columns change.
     */
    private const MONEY_COLUMNS = [
        'total', 'paid', 'balance', 'amount', 'goods', 'freight',
        'other_costs', 'total_costs', 'grand_total', 'additional_costs',
        'allocated', 'unallocated', 'revenue', 'cost', 'commission',
        'total_revenue', 'margin_amount',
    ];

    public function getDocumentTitle(): string
    {
        /** @var Company $company */
        $company = $this->model;

        return __('financial_report.title', [], $this->locale()).' — '.$company->name;
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

        return "financial-report-{$slug}-{$date}.xlsx";
    }

    /**
     * Not used — generate() is overridden to write multi-sheet output.
     * Abstract requires the methods to exist, so return empty.
     */
    protected function getHeaders(): array
    {
        return [];
    }

    protected function getRows(): array
    {
        return [];
    }

    public function generate(): string
    {
        $report = $this->report();
        $locale = $this->locale();

        $filename = $this->getFilename();
        $path = storage_path('app/temp/'.$filename);
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $writer = new Writer;
        $writer->openToFile($path);

        $usedSheetNames = [];

        // --- Summary sheet ---
        $writer->getCurrentSheet()->setName(
            $this->makeSheetName(__('financial_report.sheets.summary', [], $locale), $usedSheetNames)
        );
        $this->writeSummarySheet($writer, $report, $locale);

        // --- One sheet per non-empty section ---
        foreach ($report->nonEmptySections() as $section) {
            $sheetName = $this->makeSheetName(
                __($section->titleKey, [], $locale),
                $usedSheetNames,
            );
            $writer->addNewSheetAndMakeItCurrent()->setName($sheetName);
            $this->writeSectionSheet($writer, $section, $locale);
        }

        $writer->close();

        return $path;
    }

    private function writeSummarySheet(Writer $writer, FinancialReportData $report, string $locale): void
    {
        /** @var Company $company */
        $company = $this->model;

        $titleStyle = (new Style)->setFontBold()->setFontSize(14);
        $infoStyle = (new Style)->setFontSize(10)->setFontColor('808080');
        $tableTitleStyle = (new Style)
            ->setFontBold()
            ->setFontSize(12)
            ->setBackgroundColor('4472C4')
            ->setFontColor(Color::WHITE);
        $headerStyle = (new Style)
            ->setFontBold()
            ->setFontSize(11)
            ->setBackgroundColor('D9E1F2');

        $writer->addRow(new Row(
            [Cell::fromValue($this->getDocumentTitle())],
            $titleStyle,
        ));
        $writer->addRow(new Row(
            [Cell::fromValue($this->companySettings->company_name ?? '')],
            $infoStyle,
        ));
        $writer->addRow(new Row(
            [Cell::fromValue(
                __('financial_report.period', [], $locale).': '.
                $report->periodFrom->format('Y-m-d').' — '.$report->periodTo->format('Y-m-d')
            )],
            $infoStyle,
        ));
        $writer->addRow(new Row([Cell::fromValue('')]));

        $summary = $report->financialSummary;
        if ($summary === null || $summary->isEmpty()) {
            $writer->addRow(new Row(
                [Cell::fromValue(__('financial_report.no_records', [], $locale))],
                $infoStyle,
            ));

            return;
        }

        // Totals by currency
        $writer->addRow(new Row(
            [Cell::fromValue(__('financial_report.totals_by_currency', [], $locale))],
            $tableTitleStyle,
        ));
        $writer->addRow(new Row(
            array_map(fn ($v) => Cell::fromValue($v), [
                __('financial_report.columns.currency', [], $locale),
                __('financial_report.invoiced', [], $locale),
                __('financial_report.paid', [], $locale),
                __('financial_report.open', [], $locale),
            ]),
            $headerStyle,
        ));
        foreach ($summary->totalsByCurrency as $totals) {
            $writer->addRow(new Row(array_map(fn ($v) => Cell::fromValue($v), [
                $totals->currency,
                (float) $totals->invoiced,
                (float) $totals->paid,
                (float) $totals->open,
            ])));
        }
        $writer->addRow(new Row([Cell::fromValue('')]));

        // Aging by currency
        if ($summary->agingByCurrency !== []) {
            $writer->addRow(new Row(
                [Cell::fromValue(__('financial_report.aging', [], $locale))],
                $tableTitleStyle,
            ));
            $writer->addRow(new Row(
                array_map(fn ($v) => Cell::fromValue($v), [
                    __('financial_report.columns.currency', [], $locale),
                    __('financial_report.days_0_30', [], $locale),
                    __('financial_report.days_31_60', [], $locale),
                    __('financial_report.days_61_90', [], $locale),
                    __('financial_report.days_90_plus', [], $locale),
                ]),
                $headerStyle,
            ));
            foreach ($summary->agingByCurrency as $aging) {
                $writer->addRow(new Row(array_map(fn ($v) => Cell::fromValue($v), [
                    $aging->currency,
                    (float) $aging->bucket0to30,
                    (float) $aging->bucket31to60,
                    (float) $aging->bucket61to90,
                    (float) $aging->bucket90plus,
                ])));
            }
            $writer->addRow(new Row([Cell::fromValue('')]));
        }

        // Breakdown by document type
        if ($summary->breakdownByDocumentType !== []) {
            $writer->addRow(new Row(
                [Cell::fromValue(__('financial_report.breakdown_by_document_type', [], $locale))],
                $tableTitleStyle,
            ));
            $writer->addRow(new Row(
                array_map(fn ($v) => Cell::fromValue($v), [
                    __('financial_report.columns.currency', [], $locale),
                    __('financial_report.columns.direction', [], $locale),
                    __('financial_report.columns.total', [], $locale),
                ]),
                $headerStyle,
            ));
            foreach ($summary->breakdownByDocumentType as $currency => $byType) {
                foreach ($byType as $docType => $total) {
                    $writer->addRow(new Row(array_map(fn ($v) => Cell::fromValue($v), [
                        (string) $currency,
                        (string) $docType,
                        (float) $total,
                    ])));
                }
            }
        }
    }

    private function writeSectionSheet(Writer $writer, StatementSection $section, string $locale): void
    {
        $titleStyle = (new Style)->setFontBold()->setFontSize(14);
        $headerStyle = (new Style)
            ->setFontBold()
            ->setFontSize(11)
            ->setBackgroundColor('4472C4')
            ->setFontColor(Color::WHITE);
        $detailStyle = (new Style)
            ->setFontItalic()
            ->setFontSize(10)
            ->setFontColor('666666')
            ->setBackgroundColor('F9FAFB');

        $writer->addRow(new Row(
            [Cell::fromValue(
                __($section->titleKey, [], $locale).' ('.count($section->rows).')'
            )],
            $titleStyle,
        ));
        $writer->addRow(new Row([Cell::fromValue('')]));

        // Header row
        $writer->addRow(new Row(
            array_map(
                fn (string $col) => Cell::fromValue(__('financial_report.columns.'.$col, [], $locale)),
                $section->columns,
            ),
            $headerStyle,
        ));

        // Data rows
        foreach ($section->rows as $row) {
            $isDetail = (($row['_row_type'] ?? null) === 'detail');
            $cells = [];
            foreach ($section->columns as $col) {
                $value = $row[$col] ?? null;
                if ($value === null || $value === '') {
                    $cells[] = Cell::fromValue($isDetail ? '' : '—');

                    continue;
                }
                if (in_array($col, self::MONEY_COLUMNS, true)) {
                    $cells[] = Cell::fromValue((float) $value);

                    continue;
                }
                $cells[] = Cell::fromValue($value);
            }
            $writer->addRow(new Row($cells, $isDetail ? $detailStyle : null));
        }
    }

    private function report(): FinancialReportData
    {
        $report = $this->options['report'] ?? null;

        if (! $report instanceof FinancialReportData) {
            throw new \InvalidArgumentException(
                'FinancialReportExcelTemplate requires a FinancialReportData in options["report"].'
            );
        }

        return $report;
    }

    private function locale(): string
    {
        return (string) ($this->options['locale'] ?? app()->getLocale());
    }

    /**
     * Excel sheet names are capped at 31 chars and forbid: \ / ? * [ ] :
     * Falls back to a numeric suffix on collision.
     *
     * @param  array<string,bool>  $used  Tracker of names already used in this workbook
     */
    private function makeSheetName(string $name, array &$used): string
    {
        $clean = preg_replace('#[\\\\/\\?\\*\\[\\]:]#', ' ', $name) ?? $name;
        $clean = trim($clean);
        if ($clean === '') {
            $clean = 'Sheet';
        }
        $base = mb_substr($clean, 0, 31);
        $candidate = $base;
        $i = 2;
        while (isset($used[$candidate])) {
            $suffix = '_'.$i;
            $candidate = mb_substr($base, 0, 31 - mb_strlen($suffix)).$suffix;
            $i++;
        }
        $used[$candidate] = true;

        return $candidate;
    }
}
