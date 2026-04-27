<?php

namespace App\Domain\Financial\Reports;

use App\Domain\Financial\Reports\DTOs\PaymentsSummaryReport;
use App\Domain\Infrastructure\Support\Money;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

final class PaymentsSummaryExcelExporter
{
    private Style $headerStyle;

    private Style $totalsStyle;

    public function export(PaymentsSummaryReport $report, string $filePath): void
    {
        $writer = new Writer;
        $writer->openToFile($filePath);

        $this->headerStyle = (new Style)
            ->setFontBold()
            ->setFontSize(11)
            ->setBackgroundColor('1F3864')
            ->setFontColor(Color::WHITE);

        $this->totalsStyle = (new Style)
            ->setFontBold()
            ->setBackgroundColor('D9E2F3');

        // Sheet 1: By Company
        $writer->getCurrentSheet()->setName(__('payments_summary_report.sections.by_company'));
        $this->writeByCompanySheet($writer, $report);

        // Sheet 2: By Month
        $monthSheet = $writer->addNewSheetAndMakeItCurrent();
        $monthSheet->setName(__('payments_summary_report.sections.by_month'));
        $this->writeByMonthSheet($writer, $report);

        // Sheet 3: Payment Detail
        $allocSheet = $writer->addNewSheetAndMakeItCurrent();
        $allocSheet->setName(__('payments_summary_report.sections.allocations'));
        $this->writeAllocationsSheet($writer, $report);

        $writer->close();
    }

    private function writeByCompanySheet(Writer $writer, PaymentsSummaryReport $report): void
    {
        $this->writeMetadataRows($writer, $report);

        $writer->addRow(Row::fromValues([
            __('payments_summary_report.columns.company'),
            __('payments_summary_report.columns.payment_count'),
            __('payments_summary_report.columns.currency'),
            __('payments_summary_report.columns.amount'),
            __('payments_summary_report.columns.last_payment'),
        ], $this->headerStyle));

        foreach ($report->byCompany as $bucket) {
            $first = true;
            foreach ($bucket->totalsByCurrency as $total) {
                $writer->addRow(Row::fromValues([
                    $first ? $bucket->companyName : '',
                    $first ? $bucket->paymentCount : '',
                    $total->currency,
                    Money::toMajor($total->total),
                    $first ? ($bucket->lastPaymentDate?->toDateString() ?? '') : '',
                ]));
                $first = false;
            }
        }

        $writer->addRow(Row::fromValues([], $this->totalsStyle));
        $writer->addRow(Row::fromValues([
            __('payments_summary_report.kpi.totals_by_currency'),
            $report->totalPaymentCount,
        ], $this->totalsStyle));
        foreach ($report->grandTotalsByCurrency as $total) {
            $writer->addRow(Row::fromValues([
                '',
                $total->count,
                $total->currency,
                Money::toMajor($total->total),
            ], $this->totalsStyle));
        }
    }

    private function writeByMonthSheet(Writer $writer, PaymentsSummaryReport $report): void
    {
        $this->writeMetadataRows($writer, $report);

        $writer->addRow(Row::fromValues([
            __('payments_summary_report.columns.month'),
            __('payments_summary_report.columns.payment_count'),
            __('payments_summary_report.columns.currency'),
            __('payments_summary_report.columns.amount'),
        ], $this->headerStyle));

        foreach ($report->byMonth as $month) {
            if ($month->paymentCount === 0) {
                $writer->addRow(Row::fromValues([
                    $month->yearMonth,
                    0,
                    '',
                    '',
                ]));

                continue;
            }

            $first = true;
            foreach ($month->totalsByCurrency as $total) {
                $writer->addRow(Row::fromValues([
                    $first ? $month->yearMonth : '',
                    $first ? $month->paymentCount : '',
                    $total->currency,
                    Money::toMajor($total->total),
                ]));
                $first = false;
            }
        }
    }

    private function writeAllocationsSheet(Writer $writer, PaymentsSummaryReport $report): void
    {
        $this->writeMetadataRows($writer, $report);

        $writer->addRow(Row::fromValues([
            __('payments_summary_report.columns.date'),
            __('payments_summary_report.columns.reference'),
            __('payments_summary_report.columns.company'),
            __('payments_summary_report.columns.status'),
            __('payments_summary_report.columns.currency'),
            __('payments_summary_report.columns.amount'),
            __('payments_summary_report.columns.allocated_to'),
            __('payments_summary_report.columns.allocation_label'),
            __('payments_summary_report.columns.allocation_amount'),
        ], $this->headerStyle));

        if (empty($report->allocations)) {
            $writer->addRow(Row::fromValues([__('payments_summary_report.empty_state')]));

            return;
        }

        foreach ($report->allocations as $detail) {
            $reference = $detail->paymentReference !== '' ? $detail->paymentReference : '#'.$detail->paymentId;
            $statusLabel = $detail->paymentStatus->getLabel() ?? $detail->paymentStatus->value;

            if (empty($detail->allocations)) {
                $writer->addRow(Row::fromValues([
                    $detail->paymentDate->toDateString(),
                    $reference,
                    $detail->companyName,
                    $statusLabel,
                    $detail->currency,
                    Money::toMajor($detail->paymentAmount),
                    __('payments_summary_report.unallocated'),
                    '',
                    Money::toMajor($detail->unallocatedAmount),
                ]));

                continue;
            }

            foreach ($detail->allocations as $alloc) {
                $docCell = trim(($alloc['document_type'] ?? '').' '.($alloc['document_reference'] ?? ''));
                $writer->addRow(Row::fromValues([
                    $detail->paymentDate->toDateString(),
                    $reference,
                    $detail->companyName,
                    $statusLabel,
                    $detail->currency,
                    Money::toMajor($detail->paymentAmount),
                    $docCell,
                    (string) $alloc['label'],
                    Money::toMajor((int) $alloc['amount']),
                ]));
            }

            if ($detail->unallocatedAmount > 0) {
                $writer->addRow(Row::fromValues([
                    $detail->paymentDate->toDateString(),
                    $reference,
                    $detail->companyName,
                    $statusLabel,
                    $detail->currency,
                    Money::toMajor($detail->paymentAmount),
                    __('payments_summary_report.unallocated'),
                    '',
                    Money::toMajor($detail->unallocatedAmount),
                ]));
            }
        }
    }

    private function writeMetadataRows(Writer $writer, PaymentsSummaryReport $report): void
    {
        $direction = $report->filters->direction->value === 'inbound'
            ? __('payments_summary_report.title.receivable')
            : __('payments_summary_report.title.payable');

        $statusLabel = $report->filters->approvedOnly
            ? __('payments_summary_report.filters.status_approved_only')
            : __('payments_summary_report.filters.status_include_pending');

        $writer->addRow(Row::fromValues([$direction]));
        $writer->addRow(Row::fromValues([
            __('payments_summary_report.kpi.period'),
            $report->filters->from->toDateString().' → '.$report->filters->to->toDateString(),
        ]));
        $writer->addRow(Row::fromValues([
            __('payments_summary_report.filters.status'),
            $statusLabel,
        ]));
        $writer->addRow(Row::fromValues([])); // blank
    }
}
