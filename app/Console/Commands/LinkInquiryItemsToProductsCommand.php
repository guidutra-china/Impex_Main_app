<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\Inquiry;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Vincula inquiry items órfãos (product_id NULL) a produtos do catálogo.
 *
 * Itens criados por extração de documentos (IA) podem nascer com um código de
 * cliente inventado na descrição (ex: "DPF-LT012") e sem product_id, enquanto o
 * produto entra no catálogo depois — via import de Supplier Quotation — usando
 * o model do fornecedor (ex: "LT012"). Este comando casa a descrição do item
 * (inteira, e com o prefixo de cliente removido) contra model_number,
 * reference_code e sku dos produtos, normalizando maiúsculas e espaços.
 *
 * Só vincula quando EXATAMENTE UM produto casa; itens sem match ou ambíguos são
 * reportados e deixados como estão.
 *
 * Dry-run por default; use --apply para gravar.
 */
class LinkInquiryItemsToProductsCommand extends Command
{
    protected $signature = 'inquiries:link-items-to-products
        {inquiry : Referência da inquiry (ex: INQ-2026-00063)}
        {--apply : Persiste as mudanças (senão, dry-run)}';

    protected $description = 'Vincula inquiry items sem produto a produtos do catálogo casando a descrição com model/reference/SKU';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $inquiry = Inquiry::query()->where('reference', $this->argument('inquiry'))->first();

        if (! $inquiry) {
            $this->error('Inquiry não encontrada: '.$this->argument('inquiry'));

            return self::FAILURE;
        }

        $orphans = $inquiry->items()->whereNull('product_id')->orderBy('sort_order')->get();

        if ($orphans->isEmpty()) {
            $this->info('Nenhum item sem produto nessa inquiry.');

            return self::SUCCESS;
        }

        $productsByCode = $this->productsByNormalizedCode();

        $linkable = [];
        $unmatched = [];
        $ambiguous = [];

        foreach ($orphans as $item) {
            $candidates = collect();

            foreach ($this->candidateCodes((string) $item->description) as $code) {
                $candidates = $productsByCode->get($code, collect());

                if ($candidates->isNotEmpty()) {
                    break;
                }
            }

            if ($candidates->isEmpty()) {
                $unmatched[] = $item;
            } elseif ($candidates->count() > 1) {
                $ambiguous[] = [$item, $candidates];
            } else {
                $linkable[] = [$item, $candidates->first()];
            }
        }

        $this->info(sprintf(
            'Itens sem produto: %d — vinculáveis: %d | sem match: %d | ambíguos: %d',
            $orphans->count(),
            count($linkable),
            count($unmatched),
            count($ambiguous),
        ));

        if ($linkable !== []) {
            $this->table(
                ['Item', 'Descrição', '→ Produto', 'SKU', 'Model'],
                collect($linkable)->map(fn (array $row) => [
                    $row[0]->id,
                    $row[0]->description,
                    '#'.$row[1]->id.' '.mb_strimwidth($row[1]->name, 0, 35, '…'),
                    $row[1]->sku,
                    $row[1]->model_number,
                ])->all(),
            );
        }

        if ($unmatched !== []) {
            $this->warn('Sem match: '.collect($unmatched)->pluck('description')->implode(', '));
        }

        foreach ($ambiguous as [$item, $candidates]) {
            $this->warn(sprintf(
                'Ambíguo: "%s" casa com produtos %s — deixado como está.',
                $item->description,
                $candidates->pluck('id')->map(fn ($id) => '#'.$id)->implode(', '),
            ));
        }

        if ($linkable === []) {
            $this->info('Nada a vincular.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY-RUN: nada foi gravado. Rode com --apply para persistir.');

            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($linkable as [$item, $product]) {
            $item->product_id = $product->id;
            $item->save();
            $updated++;
        }

        $this->info("✓ Vinculados: {$updated} inquiry items.");

        return self::SUCCESS;
    }

    /**
     * Códigos candidatos derivados da descrição, do mais específico ao menos:
     * a descrição inteira e, em seguida, com o prefixo de cliente (primeiro
     * segmento antes de "-") removido.
     *
     * @return array<int, string>
     */
    protected function candidateCodes(string $description): array
    {
        $codes = [$this->normalize($description)];

        if (str_contains($description, '-')) {
            $codes[] = $this->normalize(explode('-', $description, 2)[1]);
        }

        return array_values(array_unique(array_filter($codes)));
    }

    /**
     * Produtos ativos agrupados por cada código normalizado (model_number,
     * reference_code, sku).
     *
     * @return Collection<string, Collection<int, Product>>
     */
    protected function productsByNormalizedCode(): Collection
    {
        $map = [];

        Product::query()
            ->get(['id', 'name', 'sku', 'reference_code', 'model_number'])
            ->each(function (Product $product) use (&$map) {
                foreach ([$product->model_number, $product->reference_code, $product->sku] as $code) {
                    $normalized = $this->normalize((string) $code);

                    if ($normalized === '') {
                        continue;
                    }

                    $map[$normalized] ??= collect();

                    if (! $map[$normalized]->contains('id', $product->id)) {
                        $map[$normalized]->push($product);
                    }
                }
            });

        return collect($map);
    }

    protected function normalize(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }
}
