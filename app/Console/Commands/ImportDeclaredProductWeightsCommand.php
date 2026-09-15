<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Grava no catálogo o peso de 1 peça declarado por um fornecedor.
 *
 * O arquivo (database/data/product-weights/<packing list>.json) é a fonte
 * versionada: dev e prod rodam o mesmo comando sobre o mesmo JSON, casando por
 * SKU — nunca por id. Cada SKU traz `net` e `gross` de uma peça; o comando
 * escreve o líquido unitário (product_specifications.net_weight) e o cartão de
 * embalagem (carton_net_weight / carton_weight, criando a linha com 1 pç/cx).
 *
 * Só preenche o que está vazio. Produto cujo líquido cadastrado difere do
 * arquivo é um conflito: fica intacto e aparece no relatório, até alguém
 * decidir com --overwrite (que então troca líquido, NW e bruto da caixa).
 *
 * Dry-run por padrão; passe --apply para gravar.
 */
class ImportDeclaredProductWeightsCommand extends Command
{
    protected $signature = 'products:import-declared-weights
        {--file= : Caminho do JSON (padrão: database/data/product-weights/<nome>.json)}
        {--overwrite : Substitui pesos já preenchidos que divergem do arquivo}
        {--apply : Grava as alterações (padrão: dry-run)}';

    protected $description = 'Grava líquido unitário e peso da caixa-mestre declarados por um fornecedor (JSON por SKU)';

    public function handle(): int
    {
        $path = (string) $this->option('file');

        if ($path === '') {
            $this->error('Informe --file=<caminho do JSON>.');

            return self::FAILURE;
        }

        if (! is_file($path)) {
            $candidate = database_path('data/product-weights/'.basename($path, '.json').'.json');
            $path = is_file($candidate) ? $candidate : $path;
        }

        $weights = $this->readFile($path);
        $this->line("Arquivo: <info>{$path}</info> — ".count($weights).' SKU(s)');

        $products = Product::query()->with(['specification', 'packaging'])
            ->whereIn('sku', array_keys($weights))->get()->keyBy('sku');

        $rows = [];
        $writes = [];
        $conflicts = [];
        $missing = [];
        $complete = 0;

        foreach ($weights as $sku => $declared) {
            $product = $products->get($sku);

            if (! $product) {
                $missing[] = $sku;

                continue;
            }

            $net = round((float) $declared['net'], 3);
            $gross = isset($declared['gross']) ? round((float) $declared['gross'], 3) : null;
            $current = $product->specification?->net_weight;
            $packaging = $product->packaging;

            $diverges = $current !== null && abs((float) $current - $net) >= 0.0005;

            if ($diverges && ! $this->option('overwrite')) {
                $conflicts[] = [$sku, mb_substr($product->name, 0, 40), number_format((float) $current, 3), number_format($net, 3)];

                continue;
            }

            $spec = [];
            $pack = [];

            if ($current === null || $diverges) {
                $spec['net_weight'] = $net;
            }

            if ($packaging === null || $packaging->carton_net_weight === null || $diverges) {
                $pack['carton_net_weight'] = $net;
            }

            if ($gross !== null && ($packaging === null || $packaging->carton_weight === null || $diverges)) {
                $pack['carton_weight'] = $gross;
            }

            if ($spec === [] && $pack === []) {
                $complete++;

                continue;
            }

            if ($packaging === null) {
                $pack['pcs_per_carton'] = 1;
            }

            $writes[$product->id] = ['spec' => $spec, 'pack' => $pack];
            $rows[] = [
                $sku,
                mb_substr($product->name, 0, 40),
                $current === null ? '—' : number_format((float) $current, 3),
                isset($spec['net_weight']) ? number_format($net, 3) : '(mantido)',
                isset($pack['carton_net_weight']) ? number_format($net, 3) : '(mantido)',
                isset($pack['carton_weight']) ? number_format($gross, 3) : ($gross === null ? '—' : '(mantido)'),
            ];
        }

        $this->newLine();

        if ($rows !== []) {
            $this->table(['SKU', 'Produto', 'Líquido atual', 'Líquido/pç', 'NW caixa', 'Bruto caixa'], $rows);
        }

        if ($conflicts !== []) {
            $this->warn('Em conflito (líquido cadastrado ≠ arquivo) — intactos, use --overwrite para trocar:');
            $this->table(['SKU', 'Produto', 'Cadastro', 'Arquivo'], $conflicts);
        }

        foreach ($missing as $sku) {
            $this->line("  <error>SKU não encontrado:</error> {$sku}");
        }

        $this->line(sprintf(
            'SKUs: %d — %d a gravar, %d já completos, %d em conflito, %d não encontrados.',
            count($weights), count($writes), $complete, count($conflicts), count($missing),
        ));

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry-run — nada foi gravado. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($writes, $products) {
            foreach ($writes as $productId => $w) {
                $product = $products->firstWhere('id', $productId);

                if ($w['spec'] !== []) {
                    $product->specification()->updateOrCreate([], $w['spec']);
                }

                if ($w['pack'] !== []) {
                    $product->packaging()->updateOrCreate([], $w['pack']);
                }
            }
        });

        $this->newLine();
        $this->info(sprintf('✓ %d produto(s) gravado(s).', count($writes)));

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{net: float|int, gross?: float|int}>
     */
    private function readFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Arquivo não encontrado: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['weights'] ?? null)) {
            throw new RuntimeException("JSON inválido (esperado objeto com chave 'weights'): {$path}");
        }

        foreach ($data['weights'] as $sku => $w) {
            if (! is_array($w) || ! isset($w['net']) || ! is_numeric($w['net']) || (float) $w['net'] <= 0) {
                throw new RuntimeException("Peso líquido inválido para {$sku} em {$path}");
            }
        }

        return $data['weights'];
    }
}
