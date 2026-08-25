<?php

namespace App\Domain\Logistics\Reports;

use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Versão Excel do Packing List.
 *
 * Consome o mesmo payload do template do PDF ({@see PackingListPdfTemplate}),
 * preservando o agrupamento por container, os subtotais por grupo e o total
 * geral — a planilha bate linha a linha com o PDF.
 */
class PackingListExcelExporter
{
    use Concerns\WritesShipmentDocumentSheets;

    private const COLUMN_WIDTHS = [
        'A' => 12, 'B' => 18, 'C' => 45, 'D' => 8, 'E' => 11,
        'F' => 10, 'G' => 12, 'H' => 12, 'I' => 20, 'J' => 12,
    ];

    private const LAST_COLUMN = 'J';

    /**
     * @param  array<string, mixed>  $options  opções de nomenclatura do modal
     *                                         (naming_code_source, naming_name_source…) —
     *                                         mesmo formato que o PDF já aceita.
     * @return string caminho absoluto do .xlsx gerado
     */
    public function export(Shipment $shipment, array $options = [], string $locale = 'en'): string
    {
        $data = (new PackingListPdfTemplate($shipment, $locale, $options))->getData();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Packing List');

        $row = $this->writeDocumentHeader(
            $sheet,
            title: 'PACKING LIST',
            company: $data['company'],
            client: $data['client'],
            meta: array_filter([
                'Reference' => $data['shipment']['reference'],
                'Date' => $data['shipment']['date'],
                'PI Reference' => $data['shipment']['pi_references'],
                'B/L' => $data['shipment']['bl_number'],
                'Vessel' => $data['shipment']['vessel_name'],
                'ETD' => $data['shipment']['etd'],
                'Origin' => $data['shipment']['origin_port'],
                'Destination' => $data['shipment']['destination_port'],
            ], fn ($value) => filled($value)),
            lastColumn: self::LAST_COLUMN,
        );

        $this->writeTableHeader($sheet, $row, [
            'A' => 'PKG NO.',
            'B' => 'MODEL NO.',
            'C' => 'PRODUCT NAME',
            'D' => 'UNIT',
            'E' => 'EQUIP QTY',
            'F' => 'PKG QTY',
            'G' => 'NW (KG)',
            'H' => 'GW (KG)',
            'I' => 'DIMENSIONS (cm)',
            'J' => 'VOL (m³)',
        ]);
        $firstDataRow = ++$row;

        foreach ($data['container_groups'] as $group) {
            if ($data['has_multiple_containers'] || $group['container_number']) {
                $label = $group['container_number']
                    ? 'CONTAINER: '.$group['container_number']
                    : 'NO CONTAINER ASSIGNED';

                $sheet->setCellValue("A{$row}", $label);
                $sheet->mergeCells('A'.$row.':'.self::LAST_COLUMN.$row);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('E7E6E6');
                $row++;
            }

            foreach ($group['lines'] as $line) {
                $this->writeLine($sheet, $row, $line);
                $row++;
            }

            if ($data['has_multiple_containers']) {
                $this->writeTotalsRow(
                    $sheet,
                    $row,
                    $this->totalsLabel('Subtotal '.($group['container_number'] ?? ''), $group['totals']),
                    $group['totals']['equipment_qty'],
                    $group['totals']['packages'],
                    $group['totals']['net_weight'],
                    $group['totals']['gross_weight'],
                    $group['totals']['volume'],
                    background: 'F2F2F2',
                );
                $row++;
            }
        }

        $lastDataRow = $row - 1;

        $this->writeTotalsRow(
            $sheet,
            $row,
            $this->totalsLabel('GRAND TOTAL', $data['totals']),
            $data['totals']['total_equipment_qty'],
            $data['totals']['total_packages'],
            $data['totals']['total_net_weight'],
            $data['totals']['total_gross_weight'],
            $data['totals']['total_volume'],
            background: 'D9E2F3',
        );

        if ($lastDataRow >= $firstDataRow) {
            $sheet->getStyle('A'.$firstDataRow.':'.self::LAST_COLUMN.$row)
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        $this->applyColumnWidths($sheet, self::COLUMN_WIDTHS);

        return $this->save($spreadsheet, 'packing-list-'.Str::slug($shipment->reference));
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function writeLine(Worksheet $sheet, int $row, array $line): void
    {
        $packageNo = collect([
            $line['package_no'] ?? null,
            $line['packaging_type'] ?? null,
            $line['pallet'] ?? null,
        ])->filter()->implode(' — ');

        $product = $line['product_name'];
        if (filled($line['description'] ?? null)) {
            $product .= "\n".$line['description'];
        }

        // Sub-itens (produtos dentro da mesma caixa) ficam recuados, como no PDF.
        if ($line['is_sub_item'] ?? false) {
            $product = '    '.$product;
        }

        $sheet->setCellValue("A{$row}", $packageNo);
        $sheet->setCellValue("B{$row}", $line['model_no']);
        $sheet->setCellValue("C{$row}", $product);
        $sheet->setCellValue("D{$row}", $line['unit']);
        $sheet->setCellValue("E{$row}", $line['equipment_qty'] ?: null);
        $sheet->setCellValue("F{$row}", $line['package_qty'] ?: null);
        $sheet->setCellValue("G{$row}", $this->toNumberOrNull($line['net_weight']));
        $sheet->setCellValue("H{$row}", $this->toNumberOrNull($line['gross_weight']));
        $sheet->setCellValue("I{$row}", $line['dimensions']);
        $sheet->setCellValue("J{$row}", $this->toNumberOrNull($line['volume']));

        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
        $sheet->getStyle("C{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle("D{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("G{$row}:H{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("J{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
    }

    /**
     * Quando há pallet, PKG QTY conta bultos (caixa solta = 1, pallet = 1) e
     * deixa de bater com a soma da coluna, que é por caixa. O rótulo explica
     * a diferença para quem lê a planilha.
     *
     * @param  array<string, mixed>  $totals
     */
    private function totalsLabel(string $label, array $totals): string
    {
        if ((int) ($totals['pallets'] ?? 0) < 1) {
            return $label;
        }

        return $label.' (PKG QTY = '.$totals['loose_cartons'].' CTN + '.$totals['pallets'].' PLT · '.$totals['cartons'].' CTN TOTAL)';
    }

    private function writeTotalsRow(
        Worksheet $sheet,
        int $row,
        string $label,
        int|float $equipmentQty,
        int|float $packages,
        float $netWeight,
        float $grossWeight,
        float $volume,
        string $background,
    ): void {
        $sheet->setCellValue("A{$row}", $label);
        $sheet->mergeCells("A{$row}:D{$row}");
        $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $sheet->setCellValue("E{$row}", $equipmentQty);
        $sheet->setCellValue("F{$row}", $packages);
        $sheet->setCellValue("G{$row}", $netWeight);
        $sheet->setCellValue("H{$row}", $grossWeight);
        $sheet->setCellValue("J{$row}", $volume);

        $sheet->getStyle("G{$row}:H{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle("J{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row.':'.self::LAST_COLUMN.$row)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB($background);
        $sheet->getStyle("E{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private function toNumberOrNull(?string $formatted): ?float
    {
        return filled($formatted) ? $this->toNumber($formatted) : null;
    }
}
