<?php

namespace App\Domain\Catalog\Reports;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Relatório Excel dos produtos vinculados a um cliente: dados originais do
 * produto lado a lado com os dados cadastrados para o cliente (código, nome,
 * descrição, preços) e foto embutida.
 *
 * Uses PhpSpreadsheet instead of the OpenSpout-based AbstractExcelTemplate
 * because OpenSpout cannot embed images.
 */
class ClientProductsExcelExporter
{
    /**
     * Header labels are chosen to be auto-mapped by the Quick Import
     * (FlexibleProductImportAction::fieldPatterns), so the exported file can
     * be edited and re-imported on the Products (Client) tab. Column order
     * matters: "Product Name" must come before "Model Number", otherwise the
     * auto-mapper assigns product_name to the model column. The SKU in column
     * B is the product match key on re-import.
     */
    private const HEADERS = [
        'A' => 'Photo',
        'B' => 'Reference Code (SKU)',
        'C' => 'Product Name',
        'D' => 'Model Number',
        'E' => 'Original Description',
        'F' => 'Category',
        'G' => 'Client Code',
        'H' => 'Client Product Name',
        'I' => 'Invoice Description',
        'J' => 'Selling Price',
        'K' => 'Custom Price (CI)',
        'L' => 'Currency',
    ];

    private const COLUMN_WIDTHS = [
        'A' => 12, 'B' => 18, 'C' => 35, 'D' => 16, 'E' => 45, 'F' => 18,
        'G' => 18, 'H' => 35, 'I' => 45, 'J' => 14, 'K' => 14, 'L' => 8,
    ];

    /**
     * Generates the report and returns the absolute path of the .xlsx file.
     */
    public function export(Company $client): string
    {
        $products = $client->clientProducts()
            ->with('category')
            ->orderBy('name')
            ->get();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Produtos');

        $this->writeHeader($sheet, $client);

        $row = 5;
        foreach ($products as $product) {
            $this->writeProductRow($sheet, $product, $row);
            $row++;
        }

        if ($row > 5) {
            $sheet->getStyle('A5:L'.($row - 1))
                ->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);
        }

        $directory = storage_path('app/temp');
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $path = $directory.'/produtos-'.Str::slug($client->name).'-'.now()->format('Y-m-d').'.xlsx';

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function writeHeader(Worksheet $sheet, Company $client): void
    {
        $sheet->setCellValue('A1', 'Produtos — '.$client->name);
        $sheet->mergeCells('A1:L1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Gerado em '.now()->format('d/m/Y H:i'));
        $sheet->mergeCells('A2:L2');
        $sheet->getStyle('A2')->getFont()->setSize(10)->getColor()->setRGB('808080');

        $sheet->setCellValue('B3', 'Produto (Original)');
        $sheet->mergeCells('B3:F3');
        $sheet->setCellValue('G3', 'Dados do Cliente');
        $sheet->mergeCells('G3:I3');
        $sheet->setCellValue('J3', 'Preços');
        $sheet->mergeCells('J3:L3');
        $sheet->getStyle('A3:L3')->getFont()->setBold(true);
        $sheet->getStyle('A3:L3')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER);

        foreach (self::HEADERS as $column => $label) {
            $sheet->setCellValue($column.'4', $label);
        }

        $headerStyle = $sheet->getStyle('A4:L4');
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('4472C4');

        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeProductRow(Worksheet $sheet, Product $product, int $row): void
    {
        $pivot = $product->pivot;

        $sheet->setCellValue('B'.$row, $product->sku);
        $sheet->setCellValue('C'.$row, $product->name);
        $sheet->setCellValue('D'.$row, $product->model_number);
        $sheet->setCellValue('E'.$row, $product->description);
        $sheet->setCellValue('F'.$row, $product->category?->name);
        $sheet->setCellValue('G'.$row, $pivot->external_code);
        $sheet->setCellValue('H'.$row, $pivot->external_name);
        $sheet->setCellValue('I'.$row, $pivot->external_description);
        $sheet->setCellValue('J'.$row, Money::toMajor($pivot->unit_price));

        if ($pivot->custom_price !== null) {
            $sheet->setCellValue('K'.$row, Money::toMajor($pivot->custom_price));
        }

        $sheet->setCellValue('L'.$row, $pivot->currency_code);

        $sheet->getStyle('J'.$row.':K'.$row)
            ->getNumberFormat()->setFormatCode('#,##0.0000');
        $sheet->getStyle('E'.$row)->getAlignment()->setWrapText(true);
        $sheet->getStyle('I'.$row)->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($row)->setRowHeight(48);

        $this->embedPhoto($sheet, $product, $row);
    }

    /**
     * Embeds the client-specific photo when set, falling back to the original
     * product photo. Missing or unreadable files are skipped silently.
     */
    private function embedPhoto(Worksheet $sheet, Product $product, int $row): void
    {
        $pivot = $product->pivot;

        $candidates = [];

        if ($pivot->avatar_path) {
            $candidates[] = [$pivot->avatar_disk ?? 'public', $pivot->avatar_path];
        }

        if ($product->avatar) {
            $candidates[] = ['public', $product->avatar];
        }

        foreach ($candidates as [$disk, $relativePath]) {
            try {
                $absolutePath = Storage::disk($disk)->path($relativePath);

                if (! is_file($absolutePath)) {
                    continue;
                }

                $drawing = new Drawing;
                $drawing->setPath($absolutePath);
                $drawing->setHeight(56);
                $drawing->setCoordinates('A'.$row);
                $drawing->setOffsetX(5);
                $drawing->setOffsetY(4);
                $drawing->setWorksheet($sheet);

                return;
            } catch (\Throwable) {
                continue;
            }
        }
    }
}
