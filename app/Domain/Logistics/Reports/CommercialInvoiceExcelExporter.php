<?php

namespace App\Domain\Logistics\Reports;

use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Versão Excel da Commercial Invoice.
 *
 * Consome o mesmo payload do template do PDF ({@see CommercialInvoicePdfTemplate}),
 * então preços (custom price / fórmula), nomes e códigos do cliente saem
 * idênticos aos do documento em PDF — uma fonte de verdade só.
 */
class CommercialInvoiceExcelExporter
{
    use Concerns\WritesShipmentDocumentSheets;

    /** Larguras por papel da coluna; as letras mudam quando a coluna NCM entra. */
    private const WIDTH_BY_ROLE = [
        'index' => 6, 'model' => 20, 'ncm' => 12, 'product' => 55,
        'qty' => 10, 'unit' => 8, 'unit_price' => 14, 'total' => 16,
    ];

    /** @var array<string, string> papel => letra da coluna */
    private array $columns = [];

    private string $lastColumn = 'G';

    /**
     * @param  array<string, mixed>  $options  mesmas opções do modal do PDF
     *                                         (use_custom_prices, price_formula, include_freight…)
     * @return string caminho absoluto do .xlsx gerado
     */
    public function export(Shipment $shipment, array $options = [], string $locale = 'en'): string
    {
        $data = (new CommercialInvoicePdfTemplate($shipment, $locale, $options))->getData();

        $this->mapColumns(showNcm: (bool) ($data['show_ncm'] ?? false));

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Commercial Invoice');

        $row = $this->writeDocumentHeader(
            $sheet,
            title: 'COMMERCIAL INVOICE',
            company: $data['company'],
            client: $data['client'],
            meta: array_filter([
                'Reference' => $data['shipment']['reference'],
                'Date' => $data['shipment']['date'],
                'PI Reference' => $data['shipment']['pi_references'],
                'Incoterm' => $data['shipment']['incoterm'],
                'Currency' => $data['shipment']['currency_code'],
                'Origin' => $data['shipment']['origin_port'],
                'Destination' => $data['shipment']['destination_port'],
            ], fn ($value) => filled($value)),
            lastColumn: $this->lastColumn,
        );

        $currency = $data['shipment']['currency_code'];
        $col = $this->columns;

        $headers = [
            $col['index'] => '#',
            $col['model'] => 'MODEL NO.',
            $col['product'] => 'PRODUCT',
            $col['qty'] => 'QTY',
            $col['unit'] => 'UNIT',
            $col['unit_price'] => "UNIT {$currency}",
            $col['total'] => "TOTAL {$currency}",
        ];

        if (isset($col['ncm'])) {
            $headers[$col['ncm']] = 'NCM';
        }

        ksort($headers);
        $this->writeTableHeader($sheet, $row, $headers);
        $firstDataRow = ++$row;

        foreach ($data['items'] as $item) {
            $product = $item['product_name'];
            if (filled($item['description'])) {
                $product .= "\n".$item['description'];
            }

            $sheet->setCellValue($col['index'].$row, $item['index']);
            $sheet->setCellValue($col['model'].$row, $item['model_no']);
            $sheet->setCellValue($col['product'].$row, $product);
            $sheet->setCellValue($col['qty'].$row, $item['quantity']);
            $sheet->setCellValue($col['unit'].$row, $item['unit']);
            // Valores numéricos, não texto: a planilha precisa somar/filtrar.
            $sheet->setCellValue($col['unit_price'].$row, $this->toNumber($item['unit_price']));
            $sheet->setCellValue($col['total'].$row, $this->toNumber($item['line_total']));

            if (isset($col['ncm'])) {
                // Texto explícito: NCM com zero à esquerda não pode virar número.
                $sheet->setCellValueExplicit(
                    $col['ncm'].$row,
                    (string) ($item['ncm'] ?? ''),
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                );
                $sheet->getStyle($col['ncm'].$row)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }

            $sheet->getStyle($col['product'].$row)->getAlignment()->setWrapText(true);
            $sheet->getStyle($col['index'].$row.':'.$col['unit'].$row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle($col['product'].$row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT)
                ->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle($col['unit_price'].$row.':'.$col['total'].$row)
                ->getNumberFormat()->setFormatCode('#,##0.0000');
            $sheet->getStyle($col['total'].$row)->getNumberFormat()->setFormatCode('#,##0.00');

            $row++;
        }

        $lastDataRow = $row - 1;

        if ($lastDataRow >= $firstDataRow) {
            $sheet->getStyle('A'.$firstDataRow.':'.$this->lastColumn.$lastDataRow)
                ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        }

        $row++;
        $row = $this->writeTotalRow($sheet, $row, 'SUBTOTAL', $currency, $data['totals']['subtotal']);

        if (filled($data['totals']['freight'])) {
            $row = $this->writeTotalRow($sheet, $row, 'FREIGHT', $currency, $data['totals']['freight']);
        }

        $row = $this->writeTotalRow($sheet, $row, 'GRAND TOTAL', $currency, $data['totals']['grand_total'], bold: true);

        $row++;
        $row = $this->writeFooterBlocks($sheet, $row, [
            'PAYMENT TERMS' => $data['payment_term']['description'] ?: $data['payment_term']['name'],
            'MANUFACTURER(S)' => is_array($data['manufacturers'] ?? null)
                ? implode(', ', $data['manufacturers'])
                : ($data['manufacturers'] ?? null),
            'BANK DETAILS' => $data['company']['bank_details'] ?? null,
        ], $this->lastColumn);

        $widths = [];
        foreach ($this->columns as $role => $letter) {
            $widths[$letter] = self::WIDTH_BY_ROLE[$role];
        }
        $this->applyColumnWidths($sheet, $widths);

        return $this->save($spreadsheet, 'commercial-invoice-'.Str::slug($shipment->reference));
    }

    /**
     * Letras das colunas conforme a coluna NCM entre ou não — assim o resto do
     * exporter nunca depende de letras fixas.
     */
    private function mapColumns(bool $showNcm): void
    {
        $roles = ['index', 'model'];

        if ($showNcm) {
            $roles[] = 'ncm';
        }

        array_push($roles, 'product', 'qty', 'unit', 'unit_price', 'total');

        $this->columns = [];
        foreach (array_values($roles) as $position => $role) {
            $this->columns[$role] = chr(ord('A') + $position);
        }

        $this->lastColumn = $this->columns['total'];
    }

    private function writeTotalRow(
        Worksheet $sheet,
        int $row,
        string $label,
        string $currency,
        string $value,
        bool $bold = false,
    ): int {
        $labelColumn = $this->columns['unit'];
        $currencyColumn = $this->columns['unit_price'];
        $valueColumn = $this->columns['total'];

        $sheet->setCellValue($labelColumn.$row, $label);
        $sheet->setCellValue($currencyColumn.$row, $currency);
        $sheet->setCellValue($valueColumn.$row, $this->toNumber($value));
        $sheet->getStyle($valueColumn.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle($labelColumn.$row.':'.$valueColumn.$row)->getFont()->setBold($bold);

        if ($bold) {
            $sheet->getStyle($labelColumn.$row.':'.$valueColumn.$row)->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setRGB('D9E2F3');
        }

        return $row + 1;
    }
}
