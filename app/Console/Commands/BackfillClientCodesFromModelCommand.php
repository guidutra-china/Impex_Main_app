<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Deriva o código de cliente (external_code do pivot company_product, role
 * client) a partir do modelo do fabricante, trocando o prefixo — ex.: o
 * modelo JG-S6727 da JiongGong vira DF-S6727 para a Deep Fitness.
 *
 * O arquivo JSON (database/data/client-codes/) é a fonte versionada da
 * operação, para rodar igual em dev e em prod: casa por model_number,
 * reference_code ou código do fornecedor (nunca por id). Por entrada:
 *
 *  - products.model_number vazio                → preenche com "model"
 *  - model_number igual a "replaces_model"      → corrige para "model"
 *  - model_number diferente (sem replaces)      → reporta, não mexe
 *  - pivot cliente inexistente / vazio          → cria / preenche com o código
 *  - pivot com o mesmo código                   → ignora
 *  - pivot com código diferente                 → reporta; só sobrescreve com
 *    --overwrite-prefix, e só se a diferença for apenas o prefixo (DPF-1646 →
 *    DF-1646). Códigos sem relação nunca são tocados.
 *
 * Dry-run por default; use --apply para gravar.
 */
class BackfillClientCodesFromModelCommand extends Command
{
    protected $signature = 'products:backfill-client-codes
        {file : JSON com o mapeamento (nome dentro de database/data/client-codes ou caminho completo)}
        {--apply : Persiste as mudanças (senão, dry-run)}
        {--overwrite-prefix : Substitui código existente quando só o prefixo difere (ex.: DPF-1646 → DF-1646)}
        {--to-prefix= : Sobrescreve o prefixo de destino do arquivo (ex.: DPF-)}';

    protected $description = 'Preenche model_number e deriva o código de cliente (pivot) a partir do modelo do fabricante, trocando o prefixo';

    /** @var list<int> */
    private array $supplierIds = [];

    public function handle(): int
    {
        $path = $this->resolvePath((string) $this->argument('file'));

        if (! is_file($path)) {
            $this->error("Arquivo não encontrado: {$path}");

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['products'] ?? null)) {
            $this->error('JSON inválido: esperado objeto com "client", "from_prefix", "to_prefix" e "products".');

            return self::FAILURE;
        }

        $client = $this->findCompany($data['client'] ?? null);

        if (! $client) {
            $this->error('Cliente não encontrado (ou ambíguo): '.json_encode($data['client'] ?? null));

            return self::FAILURE;
        }

        foreach ((array) ($data['suppliers'] ?? []) as $supplier) {
            $company = $this->findCompany($supplier, withTrashed: true);

            if (! $company) {
                $this->warn('Fornecedor não encontrado (ignorado na busca): '.json_encode($supplier));

                continue;
            }

            $this->supplierIds[] = $company->id;
        }

        $fromPrefix = (string) ($data['from_prefix'] ?? '');
        $toPrefix = (string) ($this->option('to-prefix') ?: ($data['to_prefix'] ?? ''));

        if ($fromPrefix === '' || $toPrefix === '') {
            $this->error('from_prefix e to_prefix são obrigatórios.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $overwritePrefix = (bool) $this->option('overwrite-prefix');

        $this->info(sprintf(
            'Cliente: #%d %s | prefixo %s → %s | fornecedores: %s | entradas: %d',
            $client->id,
            $client->name,
            $fromPrefix,
            $toPrefix,
            $this->supplierIds === [] ? '—' : implode(',', $this->supplierIds),
            count($data['products']),
        ));

        $rows = [];
        $modelFills = [];
        $modelFixes = [];
        $pivotCreates = [];
        $pivotFills = [];
        $pivotReplaces = [];
        $problems = [];
        $unchanged = 0;

        foreach ($data['products'] as $entry) {
            $model = trim((string) ($entry['model'] ?? ''));

            if ($model === '' || ! str_starts_with($model, $fromPrefix)) {
                $problems[] = "Entrada inválida (model vazio ou sem prefixo {$fromPrefix}): ".json_encode($entry);

                continue;
            }

            $keys = array_values(array_unique(array_merge([$model], array_map('strval', (array) ($entry['find'] ?? [])))));
            $matches = $this->findProducts($keys);

            if ($matches->isEmpty()) {
                $problems[] = "{$model}: produto não encontrado (chaves: ".implode(', ', $keys).')';

                continue;
            }

            if ($matches->count() > 1) {
                $problems[] = "{$model}: ambíguo — ".$matches->map(fn (Product $p) => "#{$p->id} {$p->sku}")->implode(', ');

                continue;
            }

            /** @var Product $product */
            $product = $matches->first();
            $clientCode = $toPrefix.substr($model, strlen($fromPrefix));
            $modelAction = '=';

            if (blank($product->model_number)) {
                $modelFills[] = [$product, $model];
                $modelAction = 'preencher';
            } elseif ($product->model_number === $model) {
                $modelAction = '=';
            } elseif (isset($entry['replaces_model']) && $product->model_number === $entry['replaces_model']) {
                $modelFixes[] = [$product, $model];
                $modelAction = "corrigir ({$product->model_number})";
            } else {
                $problems[] = "{$model}: produto #{$product->id} {$product->sku} tem model_number \"{$product->model_number}\" — mantido; código do cliente gravado mesmo assim.";
                $modelAction = "≠ {$product->model_number}";
            }

            $pivot = CompanyProduct::query()
                ->where('company_id', $client->id)
                ->where('product_id', $product->id)
                ->where('role', 'client')
                ->first();

            if ($pivot === null) {
                $pivotCreates[] = [$product, $clientCode];
                $pivotAction = 'criar';
            } elseif (blank($pivot->external_code)) {
                $pivotFills[] = [$pivot, $clientCode];
                $pivotAction = 'preencher';
            } elseif ($this->normalize($pivot->external_code) === $this->normalize($clientCode)) {
                $unchanged++;
                $pivotAction = '=';
            } elseif ($overwritePrefix && $this->differsOnlyByPrefix($pivot->external_code, $clientCode, $toPrefix)) {
                $pivotReplaces[] = [$pivot, $clientCode];
                $pivotAction = "substituir ({$pivot->external_code})";
            } else {
                $problems[] = "{$model}: cliente já usa \"{$pivot->external_code}\" (derivado seria \"{$clientCode}\") — deixado como está"
                    .($this->differsOnlyByPrefix($pivot->external_code, $clientCode, $toPrefix) ? '; --overwrite-prefix substituiria.' : '.');
                $pivotAction = "≠ {$pivot->external_code}";
            }

            $rows[] = [$model, "#{$product->id} {$product->sku}", mb_strimwidth((string) $product->name, 0, 32, '…'), $modelAction, $clientCode, $pivotAction];
        }

        $this->table(['Modelo', 'Produto', 'Nome', 'model_number', 'Código cliente', 'pivot'], $rows);

        foreach ($problems as $problem) {
            $this->warn($problem);
        }

        $this->info(sprintf(
            'model_number: preencher %d | corrigir %d — pivot cliente: criar %d | preencher %d | substituir %d | já corretos %d — avisos: %d',
            count($modelFills),
            count($modelFixes),
            count($pivotCreates),
            count($pivotFills),
            count($pivotReplaces),
            $unchanged,
            count($problems),
        ));

        $nothing = $modelFills === [] && $modelFixes === [] && $pivotCreates === [] && $pivotFills === [] && $pivotReplaces === [];

        if ($nothing) {
            $this->info('Nada a gravar.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY-RUN: nada foi gravado. Rode com --apply para persistir.');

            return self::SUCCESS;
        }

        foreach (array_merge($modelFills, $modelFixes) as [$product, $model]) {
            $product->model_number = $model;
            $product->save();
        }

        foreach ($pivotCreates as [$product, $code]) {
            CompanyProduct::create([
                'company_id' => $client->id,
                'product_id' => $product->id,
                'role' => 'client',
                'external_code' => $code,
                'unit_price' => 0,
            ]);
        }

        foreach (array_merge($pivotFills, $pivotReplaces) as [$pivot, $code]) {
            $pivot->external_code = $code;
            $pivot->save();
        }

        $this->info('✓ Gravado.');

        return self::SUCCESS;
    }

    /**
     * Casa por model_number, reference_code ou código do fornecedor (pivot
     * role supplier das empresas listadas). Ignora produtos excluídos.
     *
     * @param  list<string>  $keys
     * @return Collection<int, Product>
     */
    private function findProducts(array $keys): Collection
    {
        return Product::query()
            ->where(function ($query) use ($keys) {
                $query->whereIn('model_number', $keys)
                    ->orWhereIn('reference_code', $keys);

                if ($this->supplierIds !== []) {
                    $query->orWhereHas('companies', fn ($pivot) => $pivot
                        ->whereIn('companies.id', $this->supplierIds)
                        ->where('company_product.role', 'supplier')
                        ->whereIn('company_product.external_code', $keys));
                }
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Fornecedores podem estar soft-deletados (empresa fundida em outra) e
     * ainda assim carregar os pivots com o código do fabricante — por isso
     * $withTrashed. O cliente, não: o pivot é gravado nele.
     */
    private function findCompany(mixed $ref, bool $withTrashed = false): ?Company
    {
        $query = $withTrashed ? Company::withTrashed() : Company::query();

        if (is_int($ref) || (is_string($ref) && ctype_digit($ref))) {
            return $query->find((int) $ref);
        }

        if (! is_string($ref) || trim($ref) === '') {
            return null;
        }

        $matches = $query->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($ref))])->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function differsOnlyByPrefix(string $existing, string $target, string $toPrefix): bool
    {
        $suffix = substr($target, strlen($toPrefix));

        return $suffix !== ''
            && preg_match('/^[A-Za-z]+-(.+)$/', $this->normalize($existing), $m) === 1
            && $m[1] === $this->normalize($suffix);
    }

    private function normalize(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    private function resolvePath(string $file): string
    {
        $path = str_contains($file, '/') ? $file : database_path('data/client-codes/'.$file);

        return str_ends_with($path, '.json') ? $path : $path.'.json';
    }
}
