<?php

namespace App\Domain\ProformaInvoices\Reports;

use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\Logistics\Reports\Concerns\WritesShipmentDocumentSheets;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Versão Excel da Proforma Invoice.
 *
 * Consome o mesmo payload do template do PDF ({@see ProformaInvoicePdfTemplate}),
 * então preços (fórmula / preço especial), códigos do cliente e custos do
 * cliente saem idênticos aos do PDF — uma fonte de verdade só.
 */
class ProformaInvoiceExcelExporter
{
    use WritesShipmentDocumentSheets;

    public const DOCUMENT_TYPE = 'proforma_invoice_xlsx';

    /** Preço alterado é arquivado à parte, como o custom_price_pdf. */
    public const CUSTOM_PRICE_DOCUMENT_TYPE = 'custom_price_xlsx';

    /** Larguras por papel da coluna; as letras mudam conforme as colunas opcionais. */
    private const WIDTH_BY_ROLE = [
        'index' => 6, 'code' => 18, 'model' => 20, 'product' => 55,
        'qty' => 10, 'unit' => 8, 'unit_price' => 14, 'total' => 16,
    ];

    /** @var array<string, string> papel => letra da coluna */
    private array $columns = [];

    private string $lastColumn = 'G';

    /** True quando os preços impressos não são os da PI (fórmula ou preço especial). */
    public static function hasOverriddenPrices(array $options): bool
    {
        return filled(self::formula($options)) || (bool) ($options['use_custom_prices'] ?? false);
    }

    public static function documentType(array $options): string
    {
        return self::hasOverriddenPrices($options) ? self::CUSTOM_PRICE_DOCUMENT_TYPE : self::DOCUMENT_TYPE;
    }

    public static function filename(ProformaInvoice $pi, array $options, int $version): string
    {
        $prefix = self::hasOverriddenPrices($options) ? 'Custom-' : '';

        return $prefix.($pi->reference ?? $pi->getKey()).'-v'.$version.'.xlsx';
    }

    /**
     * @param  array<string, mixed>  $options  mesmas opções do modal do PDF
     *                                         (hide_commission, show_model_number, price_formula…)
     * @return string caminho absoluto do .xlsx gerado
     */
    public function export(ProformaInvoice $pi, array $options = [], string $locale = 'en'): string
    {
        $data = (new ProformaInvoicePdfTemplate(
            $pi,
            $locale,
            hideCommission: (bool) ($options['hide_commission'] ?? false),
            withImages: false,
            showProductCode: (bool) ($options['show_product_code'] ?? false),
            showModelNumber: (bool) ($options['show_model_number'] ?? true),
            priceFormula: self::formula($options),
            useCustomPrices: (bool) ($options['use_custom_prices'] ?? false),
            options: $options,
        ))->getData();

        $this->mapColumns(
            showCode: (bool) $data['show_product_code'],
            showModel: (bool) $data['show_model_number'],
        );

        $header = $data['proforma_invoice'];
        $currency = $header['currency_code'];
        $col = $this->columns;

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Proforma Invoice');

        $row = $this->writeDocumentHeader(
            $sheet,
            title: 'PROFORMA INVOICE',
            company: $data['company'],
            client: $data['client'],
            meta: array_filter([
                'Reference' => $header['reference'],
                'Version' => 'v'.$this->nextExcelVersion($pi, self::documentType($options)),
                'Client Ref.' => $header['client_reference'],
                'Date' => $header['issue_date'],
                'Valid Until' => $header['valid_until'],
                'Incoterm' => $header['incoterm'],
                'Currency' => $currency,
                'Inquiry Ref.' => $header['inquiry_reference'],
            ], fn ($value) => filled($value) && $value !== '—'),
            lastColumn: $this->lastColumn,
        );

        $headers = [
            $col['index'] => '#',
            $col['product'] => 'DESCRIPTION',
            $col['qty'] => 'QTY',
            $col['unit'] => 'UNIT',
            $col['unit_price'] => "UNIT {$currency}",
            $col['total'] => "TOTAL {$currency}",
        ];

        if (isset($col['code'])) {
            $headers[$col['code']] = 'PRODUCT CODE';
        }
        if (isset($col['model'])) {
            $headers[$col['model']] = 'MODEL NO.';
        }

        ksort($headers);
        $this->writeTableHeader($sheet, $row, $headers);
        $firstDataRow = ++$row;

        foreach ($data['items'] as $item) {
            $description = collect([
                $item['description'],
                $item['specifications'] ?? null,
                $item['attributes'] ?? null,
                filled($item['incoterm'] ?? null) ? 'Incoterm: '.$item['incoterm'] : null,
            ])->filter(fn ($line) => filled($line))->implode("\n");

            $sheet->setCellValue($col['index'].$row, $item['index']);
            $sheet->setCellValue($col['product'].$row, $description);
            $sheet->setCellValue($col['qty'].$row, $item['quantity']);
            $sheet->setCellValue($col['unit'].$row, $item['unit']);
            // Valores numéricos, não texto: a planilha precisa somar/filtrar.
            $sheet->setCellValue($col['unit_price'].$row, $this->toNumber($item['unit_price']));
            $sheet->setCellValue($col['total'].$row, $this->toNumber($item['line_total']));

            // Códigos como texto explícito: "00123" não pode virar número.
            foreach (['code' => 'product_code', 'model' => 'model_number'] as $role => $key) {
                if (isset($col[$role])) {
                    $sheet->setCellValueExplicit(
                        $col[$role].$row,
                        (string) ($item[$key] ?? ''),
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING,
                    );
                }
            }

            $sheet->getStyle('A'.$row.':'.$this->lastColumn.$row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER)
                ->setVertical(Alignment::VERTICAL_TOP);
            $sheet->getStyle($col['product'].$row)->getAlignment()
                ->setWrapText(true)
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle($col['unit_price'].$row.':'.$col['total'].$row)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle($col['unit_price'].$row)->getNumberFormat()->setFormatCode('#,##0.0000');
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

        // Custos do cliente (comissão, frete…) — já vêm vazios com "Hide Service Fee".
        foreach ($data['service_fees'] as $fee) {
            $row = $this->writeTotalRow($sheet, $row, (string) $fee['description'], $currency, $fee['amount']);
        }

        $row = $this->writeTotalRow($sheet, $row, 'GRAND TOTAL', $currency, $data['totals']['grand_total'], bold: true);

        $row++;
        $this->writeFooterBlocks($sheet, $row, [
            'PAYMENT TERMS' => $data['payment_term']['description'] ?: $data['payment_term']['name'],
            'NOTES' => $header['notes'],
            'BANK DETAILS' => $data['company']['bank_details'] ?? null,
        ], $this->lastColumn);

        $widths = [];
        foreach ($this->columns as $role => $letter) {
            $widths[$letter] = self::WIDTH_BY_ROLE[$role];
        }
        $this->applyColumnWidths($sheet, $widths);

        return $this->save($spreadsheet, 'proforma-invoice-'.Str::slug((string) $pi->reference));
    }

    /** A fórmula só vale quando o checkbox "aplicar fórmula" veio marcado (ou não veio). */
    private static function formula(array $options): ?string
    {
        if (array_key_exists('use_formula', $options) && ! $options['use_formula']) {
            return null;
        }

        return filled($options['price_formula'] ?? null) ? (string) $options['price_formula'] : null;
    }

    /** Letras das colunas conforme SKU e Model entrem ou não. */
    private function mapColumns(bool $showCode, bool $showModel): void
    {
        $roles = ['index'];

        if ($showCode) {
            $roles[] = 'code';
        }
        if ($showModel) {
            $roles[] = 'model';
        }

        array_push($roles, 'product', 'qty', 'unit', 'unit_price', 'total');

        $this->columns = [];
        foreach ($roles as $position => $role) {
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
        $sheet->getStyle($labelColumn.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
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
