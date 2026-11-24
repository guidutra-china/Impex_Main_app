<?php

namespace App\Domain\Financial\Reports;

use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Planilha simples das alocações de um pagamento: o mesmo conteúdo do modal
 * do Portal ({@see PaymentAllocationReport}), sem logo nem cabeçalho de
 * empresa. Valores vão como número, para o cliente somar e filtrar.
 */
class PaymentAllocationExcelExporter
{
    private const MONEY_FORMAT = '#,##0.00';

    private const RATE_FORMAT = '0.0000####';

    public static function filename(Payment $payment): string
    {
        $number = (string) ($payment->number ?: 'payment-'.$payment->getKey());

        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $number), '-').'-allocations.xlsx';
    }

    public function export(Payment $payment): string
    {
        $report = PaymentAllocationReport::forPayment($payment);
        $currency = $report['payment']['currency'];

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(Str::limit(__('forms.labels.allocations'), 31, ''));

        $row = 1;
        foreach ([
            [__('forms.labels.payment_number'), $report['payment']['number']],
            [__('forms.labels.reference_swift_transfer'), $report['payment']['reference']],
            [__('forms.labels.payment_date'), $report['payment']['date']],
        ] as [$label, $value]) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValueExplicit("B{$row}", (string) ($value ?? '—'), DataType::TYPE_STRING);
            $row++;
        }

        foreach ([
            [__('widgets.portal.payment_amount'), $report['payment']['amount']],
            [__('widgets.list_stats.allocated'), $report['totals']['allocated']],
            [__('widgets.list_stats.unallocated'), $report['totals']['unallocated']],
        ] as [$label, $amount]) {
            $sheet->setCellValue("A{$row}", $label.' ('.$currency.')');
            $this->money($sheet, "B{$row}", $amount);
            $row++;
        }
        $sheet->getStyle('A1:A'.($row - 1))->getFont()->setBold(true);
        $sheet->getStyle('B1:B'.($row - 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $row++;
        $headerRow = $row;
        $columns = $this->columns($report, $currency);

        foreach (array_values($columns) as $index => $column) {
            $sheet->setCellValue([$index + 1, $row], $column['label']);
        }
        $lastColumn = Coordinate::stringFromColumnIndex(count($columns));
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFont()->setBold(true);
        $row++;

        foreach ($report['rows'] as $line) {
            foreach (array_keys($columns) as $index => $key) {
                $cell = Coordinate::stringFromColumnIndex($index + 1).$row;

                match ($key) {
                    'index' => $sheet->setCellValue($cell, $line['index']),
                    'allocated', 'document_amount' => $this->money($sheet, $cell, $line[$key]),
                    'exchange_rate' => $line['exchange_rate'] === null
                        ? null
                        : $sheet->setCellValue($cell, $line['exchange_rate'])
                            ->getStyle($cell)->getNumberFormat()->setFormatCode(self::RATE_FORMAT),
                    'pi_ref' => $this->invoices($sheet, $cell, $line),
                    default => $sheet->setCellValueExplicit($cell, (string) ($line[$key] ?? ''), DataType::TYPE_STRING),
                };
            }
            $row++;
        }

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

        // Só o total na moeda do pagamento: a coluna do documento pode misturar moedas.
        $allocatedColumn = Coordinate::stringFromColumnIndex(array_search('allocated', array_keys($columns), true) + 1);
        $sheet->setCellValue("A{$row}", __('widgets.portal.total'));
        $this->money($sheet, $allocatedColumn.$row, $report['totals']['allocated']);
        $sheet->getStyle("A{$row}:{$lastColumn}{$row}")->getFont()->setBold(true);

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$row}")
            ->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);

        foreach (range(1, count($columns)) as $index) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index))->setAutoSize(true);
        }
        $sheet->freezePane('A'.($headerRow + 1));

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/'.Str::random(8).'-'.self::filename($payment);

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * Mesmas colunas do modal, na mesma ordem. Câmbio e valor no documento só
     * entram quando alguma alocação cruzou moedas ou usou crédito.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, array{label: string}>
     */
    private function columns(array $report, string $currency): array
    {
        $columns = [
            'index' => ['label' => '#'],
            'document_type' => ['label' => __('widgets.portal.document')],
            'document_ref' => ['label' => __('widgets.portal.reference')],
            'pi_ref' => ['label' => __('widgets.portal.allocation_details.pi')],
            'client_reference' => ['label' => __('forms.labels.client_reference')],
            'shipment_ref' => ['label' => __('widgets.portal.allocation_details.shipment')],
            'bl_number' => ['label' => __('widgets.portal.allocation_details.bl')],
            'description' => ['label' => __('widgets.portal.schedule_item')],
            'allocated' => ['label' => __('widgets.portal.allocated_amount').' ('.$currency.')'],
        ];

        if ($report['has_exchange']) {
            $columns['exchange_rate'] = ['label' => __('forms.labels.exchange_rate')];
        }

        if ($report['show_document_amount']) {
            $columns['document_amount'] = ['label' => __('widgets.portal.allocation_details.document_amount')];
            $columns['document_currency'] = ['label' => __('forms.labels.currency')];
        }

        return $columns;
    }

    /**
     * Coluna PI: em custo de embarque, uma linha por PI com a fatia do valor
     * que cabe a ela, como no modal.
     *
     * @param  array<string, mixed>  $line
     */
    private function invoices($sheet, string $cell, array $line): void
    {
        $text = $line['pi_breakdown'] === []
            ? (string) ($line['pi_ref'] ?? '')
            : collect($line['pi_breakdown'])
                ->map(fn (array $part) => $part['reference'].': '.$part['currency'].' '.Money::format($part['amount']))
                ->implode("\n");

        $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_STRING);

        if ($line['pi_breakdown'] !== []) {
            $sheet->getStyle($cell)->getAlignment()->setWrapText(true);
        }
    }

    private function money($sheet, string $cell, int $minorUnits): void
    {
        $sheet->setCellValue($cell, Money::toMajor($minorUnits));
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::MONEY_FORMAT);
    }
}
