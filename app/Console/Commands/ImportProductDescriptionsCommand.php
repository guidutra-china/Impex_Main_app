<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Importa a coluna E (Original Description) do relatório de produtos do cliente
 * para products.description.
 *
 * O ClientProductsReportImporter só grava o pivot do cliente (G..M): a coluna E
 * é descrição GLOBAL do produto e ficava de fora, então quem preenchia a
 * planilha perdia esse trabalho em silêncio. Este comando fecha essa lacuna.
 *
 * O produto é localizado pelo SKU (coluna B), igual ao importador oficial.
 * Célula em branco NÃO limpa a descrição existente — a planilha aqui é fonte de
 * preenchimento, não estado final, e uma coluna vazia não pode apagar o que já
 * existe no catálogo.
 *
 * Dry-run por padrão; --apply grava.
 */
class ImportProductDescriptionsCommand extends Command
{
    protected $signature = 'catalog:import-product-descriptions
                            {file : Caminho do .xlsx exportado pelo relatório de produtos do cliente}
                            {--apply : Grava as alterações (o padrão é apenas relatar)}
                            {--overwrite : Também substitui descrições já preenchidas (o padrão é só preencher as vazias)}';

    protected $description = 'Preenche products.description a partir da coluna E do relatório de produtos do cliente';

    private const SKU_HEADER = 'Reference Code (SKU)';

    private const DESC_HEADER = 'Original Description';

    public function handle(): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("Arquivo não encontrado: {$file}");

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $overwrite = (bool) $this->option('overwrite');

        if (! $apply) {
            $this->warn('DRY-RUN — nada será gravado. Use --apply para persistir.');
        }

        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();

        $headerRow = $this->findHeaderRow($sheet);

        if ($headerRow === null) {
            $spreadsheet->disconnectWorksheets();
            $this->error('Cabeçalho "'.self::SKU_HEADER.'" não encontrado: o arquivo não é o relatório de produtos do cliente.');

            return self::FAILURE;
        }

        // a coluna da descrição é localizada pelo rótulo, não fixada em E:
        // o layout do relatório já mudou uma vez (Selling Price entrou em J)
        $descColumn = $this->findColumn($sheet, $headerRow, self::DESC_HEADER);

        if ($descColumn === null) {
            $spreadsheet->disconnectWorksheets();
            $this->error('Coluna "'.self::DESC_HEADER.'" não encontrada na linha de cabeçalho.');

            return self::FAILURE;
        }

        $this->line("Cabeçalho na linha {$headerRow}; descrição na coluna {$descColumn}.");

        $stats = ['updated' => 0, 'unchanged' => 0, 'kept' => 0, 'blank' => 0, 'missing' => 0];
        $missing = [];
        $changes = [];

        $highestRow = $sheet->getHighestDataRow();

        DB::transaction(function () use ($sheet, $headerRow, $highestRow, $descColumn, $apply, $overwrite, &$stats, &$missing, &$changes) {
            for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
                $sku = trim((string) $sheet->getCell('B'.$row)->getValue());

                if ($sku === '') {
                    continue;
                }

                $description = trim((string) $sheet->getCell($descColumn.$row)->getValue());

                if ($description === '') {
                    $stats['blank']++;

                    continue;
                }

                $product = Product::where('sku', $sku)->first();

                if (! $product) {
                    $stats['missing']++;
                    $missing[] = $sku;

                    continue;
                }

                $current = trim((string) $product->description);

                if ($current === $description) {
                    $stats['unchanged']++;

                    continue;
                }

                if ($current !== '' && ! $overwrite) {
                    $stats['kept']++;

                    continue;
                }

                $changes[] = [$sku, $current, $description];
                $stats['updated']++;

                if ($apply) {
                    $product->update(['description' => $description]);
                }
            }

            if (! $apply) {
                DB::rollBack();
            }
        });

        $spreadsheet->disconnectWorksheets();

        foreach (array_slice($changes, 0, 10) as [$sku, $before, $after]) {
            $this->line(sprintf('  %-14s %s -> %s',
                $sku,
                $before === '' ? '(vazio)' : '"'.mb_substr($before, 0, 30).'"',
                '"'.mb_substr($after, 0, 50).'"'
            ));
        }

        if (count($changes) > 10) {
            $this->line('  ... e mais '.(count($changes) - 10).' produtos.');
        }

        $this->newLine();
        $this->table(['Resultado', 'Produtos'], [
            ['Descrição gravada', $stats['updated']],
            ['Já idêntica', $stats['unchanged']],
            ['Preservada (já tinha texto; use --overwrite)', $stats['kept']],
            ['Célula em branco na planilha', $stats['blank']],
            ['SKU inexistente no catálogo', $stats['missing']],
        ]);

        if ($missing !== []) {
            $this->warn('SKUs não encontrados: '.implode(', ', array_slice($missing, 0, 20)).(count($missing) > 20 ? ' ...' : ''));
        }

        if (! $apply) {
            $this->warn('DRY-RUN — nada foi gravado. Rode de novo com --apply.');
        }

        return self::SUCCESS;
    }

    private function findHeaderRow(Worksheet $sheet): ?int
    {
        for ($row = 1; $row <= 10; $row++) {
            if (trim((string) $sheet->getCell('B'.$row)->getValue()) === self::SKU_HEADER) {
                return $row;
            }
        }

        return null;
    }

    private function findColumn(Worksheet $sheet, int $headerRow, string $label): ?string
    {
        foreach (range('A', 'Z') as $column) {
            if (trim((string) $sheet->getCell($column.$headerRow)->getValue()) === $label) {
                return $column;
            }
        }

        return null;
    }
}
