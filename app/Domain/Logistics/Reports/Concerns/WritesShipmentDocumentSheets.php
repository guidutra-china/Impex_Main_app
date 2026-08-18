<?php

namespace App\Domain\Logistics\Reports\Concerns;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Blocos comuns às versões Excel dos documentos de embarque (Commercial
 * Invoice e Packing List): cabeçalho com emissor/cliente, faixa de cabeçalho
 * da tabela, blocos de rodapé e gravação do arquivo temporário.
 */
trait WritesShipmentDocumentSheets
{
    /**
     * @param  array<string, mixed>  $company
     * @param  array<string, mixed>  $client
     * @param  array<string, string>  $meta
     * @return int próxima linha livre
     */
    protected function writeDocumentHeader(
        Worksheet $sheet,
        string $title,
        array $company,
        array $client,
        array $meta,
        string $lastColumn,
    ): int {
        $sheet->setCellValue('A1', $title);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', $company['name'] ?? '');
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', $this->companyAddressLine($company));
        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $row = 5;

        $sheet->setCellValue("A{$row}", 'TO');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;

        foreach ($this->clientLines($client) as $line) {
            $sheet->setCellValue("A{$row}", $line);
            $sheet->mergeCells("A{$row}:C{$row}");
            $row++;
        }

        // Metadados do documento ficam à direita do bloco do cliente.
        $metaRow = 6;
        foreach ($meta as $label => $value) {
            $sheet->setCellValue("E{$metaRow}", $label);
            $sheet->getStyle("E{$metaRow}")->getFont()->setBold(true);
            $sheet->setCellValue("F{$metaRow}", (string) $value);
            $metaRow++;
        }

        return max($row, $metaRow) + 1;
    }

    /**
     * @param  array<string, string>  $headers  coluna => rótulo
     */
    protected function writeTableHeader(Worksheet $sheet, int $row, array $headers): void
    {
        foreach ($headers as $column => $label) {
            $sheet->setCellValue($column.$row, $label);
        }

        $columns = array_keys($headers);
        $range = reset($columns).$row.':'.end($columns).$row;

        $sheet->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle($range)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('1F3864');
        $sheet->getStyle($range)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * @param  array<string, string|null>  $blocks  título => conteúdo (blocos vazios são ignorados)
     */
    protected function writeFooterBlocks(Worksheet $sheet, int $row, array $blocks, string $lastColumn): int
    {
        foreach ($blocks as $title => $content) {
            if (blank($content)) {
                continue;
            }

            $sheet->setCellValue("A{$row}", $title);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $row++;

            $sheet->setCellValue("A{$row}", $content);
            $sheet->mergeCells("A{$row}:{$lastColumn}{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
            $row += 2;
        }

        return $row;
    }

    /**
     * @param  array<string, int>  $widths
     */
    protected function applyColumnWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * Converte o valor já formatado pelo template ("1,234.56") de volta para
     * número, para a célula ser somável na planilha.
     */
    protected function toNumber(?string $formatted): float
    {
        return (float) str_replace(',', '', (string) $formatted);
    }

    protected function save(Spreadsheet $spreadsheet, string $basename): string
    {
        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/'.$basename.'-'.now()->format('Y-m-d').'.xlsx';

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function companyAddressLine(array $company): string
    {
        return collect([
            $company['address'] ?? null,
            $company['city'] ?? null,
            $company['country'] ?? null,
            filled($company['phone'] ?? null) ? 'Tel: '.$company['phone'] : null,
            $company['email'] ?? null,
        ])->filter()->implode(' — ');
    }

    /**
     * @param  array<string, mixed>  $client
     * @return array<int, string>
     */
    private function clientLines(array $client): array
    {
        return collect([
            $client['name'] ?? null,
            $client['address'] ?? null,
            filled($client['tax_id'] ?? null) ? 'Tax ID: '.$client['tax_id'] : null,
            filled($client['phone'] ?? null) ? 'Phone: '.$client['phone'] : null,
            $client['email'] ?? null,
        ])->filter(fn ($line) => filled($line) && $line !== '—')->values()->all();
    }
}
