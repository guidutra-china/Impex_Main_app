<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Funde produtos duplicados criados pelo import de SQs REJEITADAS de uma
 * inquiry nos produtos homônimos das SQs vigentes.
 *
 * O import de SQ cria produtos novos em vez de reutilizar os de versões
 * anteriores da cotação; quando uma versão é rejeitada e recotada, o catálogo
 * fica com pares idênticos (ex: dois "Dumbbell 15kg"). Para cada produto
 * referenciado APENAS por SQs rejeitadas da inquiry, com exatamente um
 * homônimo no pool das SQs não-rejeitadas:
 *  - referências de negócio (SQ/inquiry/quotation/PI/PO items, projects) são
 *    reapontadas para o canônico;
 *  - pivots company_product duplicados e SEM dados são apagados (com dados
 *    divergentes → conflito reportado, par inteiro pulado); sem contraparte
 *    no canônico, o pivot é reapontado;
 *  - o produto duplicado é soft-deletado.
 *
 * Pares com dados próprios (fotos, specs, packaging, variantes, custos) são
 * pulados e reportados — merge manual. Dry-run por default; --apply grava.
 */
class DedupeRejectedSqProductsCommand extends Command
{
    protected $signature = 'products:dedupe-rejected-sq-duplicates
        {inquiry : Referência da inquiry (ex: INQ-2026-00063)}
        {--apply : Persiste as mudanças (senão, dry-run)}';

    protected $description = 'Funde produtos duplicados criados por SQs rejeitadas nos homônimos das SQs vigentes';

    /** @var array<int, array{string, string}> Referências reapontáveis (tabela, coluna). */
    protected const BUSINESS_REFS = [
        ['supplier_quotation_items', 'product_id'],
        ['inquiry_items', 'product_id'],
        ['quotation_items', 'product_id'],
        ['proforma_invoice_items', 'product_id'],
        ['purchase_order_items', 'product_id'],
        ['projects', 'product_id'],
    ];

    /** @var array<int, array{string, string}> Dados próprios do produto que bloqueiam o merge automático. */
    protected const BLOCKING_REFS = [
        ['product_attribute_values', 'product_id'],
        ['product_components', 'product_id'],
        ['product_costings', 'product_id'],
        ['product_images', 'product_id'],
        ['product_packagings', 'product_id'],
        ['product_specifications', 'product_id'],
        ['products', 'parent_id'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $inquiry = Inquiry::query()->where('reference', $this->argument('inquiry'))->first();

        if (! $inquiry) {
            $this->error('Inquiry não encontrada: '.$this->argument('inquiry'));

            return self::FAILURE;
        }

        $canonicalIds = $this->sqProductIds($inquiry, rejected: false);
        $canonicalByName = $this->groupByName($canonicalIds);

        $duplicates = Product::query()
            ->whereIn('id', $this->sqProductIds($inquiry, rejected: true)->diff($canonicalIds))
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('Nenhum produto exclusivo de SQ rejeitada nessa inquiry.');

            return self::SUCCESS;
        }

        $pairs = [];
        $skipped = [];

        foreach ($duplicates as $duplicate) {
            $twins = $canonicalByName->get($this->normalizeName($duplicate->name), collect());

            if ($twins->count() !== 1) {
                $skipped[] = sprintf('%s (%s): %d homônimos no pool vigente', $duplicate->sku, $duplicate->name, $twins->count());

                continue;
            }

            if (($blockers = $this->blockingData($duplicate)) !== []) {
                $skipped[] = sprintf('%s (%s): dados próprios em %s — merge manual', $duplicate->sku, $duplicate->name, implode(', ', $blockers));

                continue;
            }

            $pairs[] = [$duplicate, $twins->first()];
        }

        $this->info(sprintf(
            'Duplicados de SQs rejeitadas: %d — fundíveis: %d | pulados: %d',
            $duplicates->count(),
            count($pairs),
            count($skipped),
        ));

        if ($pairs !== []) {
            $this->table(
                ['Duplicado', 'Nome', '→ Canônico'],
                collect($pairs)->map(fn (array $row) => [
                    '#'.$row[0]->id.' '.$row[0]->sku,
                    mb_strimwidth($row[0]->name, 0, 40, '…'),
                    '#'.$row[1]->id.' '.$row[1]->sku,
                ])->all(),
            );
        }

        foreach ($skipped as $line) {
            $this->warn('Pulado: '.$line);
        }

        if ($pairs === []) {
            $this->info('Nada a fundir.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY-RUN: nada foi gravado. Rode com --apply para persistir.');

            return self::SUCCESS;
        }

        $merged = 0;

        foreach ($pairs as [$duplicate, $canonical]) {
            try {
                DB::transaction(function () use ($duplicate, $canonical) {
                    $this->mergePivots($duplicate, $canonical);
                    $this->repointBusinessRefs($duplicate, $canonical);
                    $duplicate->delete();
                });
                $merged++;
            } catch (\RuntimeException $e) {
                $this->warn(sprintf('Conflito em %s: %s — par pulado.', $duplicate->sku, $e->getMessage()));
            }
        }

        $this->info("✓ Fundidos (duplicado soft-deletado): {$merged} produtos.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, int>
     */
    protected function sqProductIds(Inquiry $inquiry, bool $rejected): Collection
    {
        return SupplierQuotationItem::query()
            ->whereHas('supplierQuotation', fn ($q) => $q
                ->where('inquiry_id', $inquiry->id)
                ->where('status', $rejected ? '=' : '!=', SupplierQuotationStatus::REJECTED->value))
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');
    }

    /**
     * @param  Collection<int, int>  $productIds
     * @return Collection<string, Collection<int, Product>>
     */
    protected function groupByName(Collection $productIds): Collection
    {
        $map = [];

        Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'sku'])
            ->each(function (Product $product) use (&$map) {
                $key = $this->normalizeName($product->name);
                $map[$key] ??= collect();
                $map[$key]->push($product);
            });

        return collect($map);
    }

    /**
     * Tabelas de dados próprios onde o duplicado tem linhas (bloqueia merge).
     *
     * @return array<int, string>
     */
    protected function blockingData(Product $duplicate): array
    {
        $found = [];

        foreach (self::BLOCKING_REFS as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (DB::table($table)->where($column, $duplicate->id)->exists()) {
                $found[] = $table;
            }
        }

        return $found;
    }

    protected function repointBusinessRefs(Product $duplicate, Product $canonical): void
    {
        foreach (self::BUSINESS_REFS as [$table, $column]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)
                ->where($column, $duplicate->id)
                ->update([$column => $canonical->id]);
        }
    }

    /**
     * Pivots do duplicado: apaga os vazios que já existem no canônico,
     * reaponta os sem contraparte; dados divergentes abortam o par.
     */
    protected function mergePivots(Product $duplicate, Product $canonical): void
    {
        $pivots = CompanyProduct::query()->where('product_id', $duplicate->id)->get();

        foreach ($pivots as $pivot) {
            $counterpart = CompanyProduct::query()
                ->where('product_id', $canonical->id)
                ->where('company_id', $pivot->company_id)
                ->where('role', $pivot->role)
                ->first();

            if ($counterpart === null) {
                $pivot->product_id = $canonical->id;
                $pivot->save();

                continue;
            }

            if ($this->pivotHasData($pivot)) {
                throw new \RuntimeException(sprintf(
                    'pivot (company %d, role %s) do duplicado tem dados e o canônico já tem pivot próprio',
                    $pivot->company_id,
                    $pivot->role,
                ));
            }

            $pivot->delete();
        }
    }

    protected function pivotHasData(CompanyProduct $pivot): bool
    {
        return filled($pivot->external_code)
            || filled($pivot->external_name)
            || filled($pivot->external_description)
            || (int) $pivot->unit_price !== 0
            || $pivot->custom_price !== null
            || filled($pivot->notes)
            || filled($pivot->avatar_path)
            || (bool) $pivot->is_preferred;
    }

    protected function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }
}
