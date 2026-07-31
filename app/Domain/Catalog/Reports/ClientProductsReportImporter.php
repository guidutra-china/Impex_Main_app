<?php

namespace App\Domain\Catalog\Reports;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Importa de volta o relatório gerado pelo ClientProductsExcelExporter, sem
 * wizard de mapeamento: as colunas são fixas, o produto é localizado pelo SKU
 * (coluna B) e apenas vínculos já existentes do cliente são atualizados.
 *
 * Célula em branco limpa o campo correspondente (a planilha é o estado final),
 * exceto unit_price, que é NOT NULL e volta para 0. Fotos são ignoradas.
 *
 * Colunas adicionais: NCM (M) atualiza o hs_code do PRODUTO quando preenchida —
 * em branco NÃO limpa (dado do produto, compartilhado entre clientes; um
 * arquivo antigo sem a coluna não pode apagar NCMs). Fabricante (N) é apenas
 * informativa e ignorada no import (vínculo com fornecedor não é editável por
 * planilha de cliente).
 */
class ClientProductsReportImporter
{
    private const SKU_HEADER = 'Reference Code (SKU)';

    private const HEADER_SCAN_LIMIT = 10;

    /**
     * @return array{updated: int, skipped: int}
     */
    public function import(Company $client, string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $headerRow = $this->findHeaderRow($sheet);

        if ($headerRow === null) {
            $spreadsheet->disconnectWorksheets();

            throw new InvalidArgumentException(
                'O arquivo não é um relatório de produtos do cliente: cabeçalho "'.self::SKU_HEADER.'" não encontrado.'
            );
        }

        $stats = ['updated' => 0, 'skipped' => 0];
        $highestRow = $sheet->getHighestDataRow();

        DB::transaction(function () use ($sheet, $client, $headerRow, $highestRow, &$stats) {
            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $sku = trim((string) $sheet->getCell('B'.$row)->getValue());

                if ($sku === '') {
                    continue;
                }

                $link = CompanyProduct::where('company_id', $client->id)
                    ->where('role', 'client')
                    ->whereHas('product', fn ($query) => $query->where('sku', $sku))
                    ->first();

                if (! $link) {
                    $stats['skipped']++;

                    continue;
                }

                $link->update([
                    'external_code' => $this->stringValue($sheet, 'G'.$row),
                    'external_name' => $this->stringValue($sheet, 'H'.$row),
                    'external_description' => $this->stringValue($sheet, 'I'.$row),
                    'unit_price' => Money::toMinor($this->priceValue($sheet, 'J'.$row) ?? 0),
                    'custom_price' => ($custom = $this->priceValue($sheet, 'K'.$row)) !== null
                        ? Money::toMinor($custom)
                        : null,
                    'currency_code' => $this->stringValue($sheet, 'L'.$row),
                ]);

                $ncm = $this->stringValue($sheet, 'M'.$row);
                if ($ncm !== null && $link->product && $link->product->hs_code !== $ncm) {
                    $link->product->update(['hs_code' => $ncm]);
                }

                $stats['updated']++;
            }
        });

        $spreadsheet->disconnectWorksheets();

        return $stats;
    }

    private function findHeaderRow(Worksheet $sheet): ?int
    {
        for ($row = 1; $row <= self::HEADER_SCAN_LIMIT; $row++) {
            if (trim((string) $sheet->getCell('B'.$row)->getValue()) === self::SKU_HEADER) {
                return $row;
            }
        }

        return null;
    }

    private function stringValue(Worksheet $sheet, string $coordinate): ?string
    {
        $value = trim((string) $sheet->getCell($coordinate)->getValue());

        return $value !== '' ? $value : null;
    }

    private function priceValue(Worksheet $sheet, string $coordinate): ?float
    {
        $value = $sheet->getCell($coordinate)->getValue();

        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));

            if (! is_numeric($value)) {
                return null;
            }
        }

        return (float) $value;
    }
}
