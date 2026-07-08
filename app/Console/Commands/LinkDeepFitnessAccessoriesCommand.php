<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-off: vincula os acessórios da INQ-2026-00063 (Deep Fitness) aos produtos
 * criados pelo import da SQ da Hebei Yangrun.
 *
 * Os itens da inquiry usam códigos de cliente inventados na extração
 * (DPF-WP-5, DPF-HEX-DB-3, DPF-OBB-2.2...) que não existem em nenhum produto,
 * mas os produtos correspondentes existem com nomes descritivos ("Weight plate
 * 5kg", "Dumbbell 3kg"...). O match aqui é código → nome esperado, resolvido
 * SOMENTE dentro do pool de produtos referenciados pelas SQs da própria
 * inquiry — nomes genéricos como "Dumbbell 5kg" não são seguros globalmente,
 * e SKUs sequenciais (DBE-000xx) podem divergir entre dev e produção.
 *
 * Só vincula quando exatamente um produto do pool casa. Dry-run por default;
 * use --apply para gravar.
 */
class LinkDeepFitnessAccessoriesCommand extends Command
{
    protected $signature = 'inquiries:link-deep-fitness-accessories
        {inquiry=INQ-2026-00063 : Referência da inquiry}
        {--apply : Persiste as mudanças (senão, dry-run)}';

    protected $description = 'Vincula os acessórios da inquiry Deep Fitness aos produtos das SQs (mapa código → nome)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $inquiry = Inquiry::query()->where('reference', $this->argument('inquiry'))->first();

        if (! $inquiry) {
            $this->error('Inquiry não encontrada: '.$this->argument('inquiry'));

            return self::FAILURE;
        }

        $map = $this->codeToNameMap();
        $pool = $this->sqProductPool($inquiry);

        $orphans = $inquiry->items()->whereNull('product_id')->orderBy('sort_order')->get();

        $linkable = [];
        $unmatched = [];

        foreach ($orphans as $item) {
            $code = $this->normalizeCode((string) $item->description);
            $expectedName = $map[$code] ?? null;

            if ($expectedName === null) {
                $unmatched[] = $item->description.' (sem entrada no mapa)';

                continue;
            }

            $candidates = $pool->get($this->normalizeName($expectedName), collect());

            if ($candidates->count() !== 1) {
                $unmatched[] = sprintf(
                    '%s (esperava "%s": %d produtos no pool das SQs)',
                    $item->description,
                    $expectedName,
                    $candidates->count(),
                );

                continue;
            }

            $linkable[] = [$item, $candidates->first()];
        }

        $this->info(sprintf(
            'Itens sem produto: %d — vinculáveis: %d | sem match: %d',
            $orphans->count(),
            count($linkable),
            count($unmatched),
        ));

        if ($linkable !== []) {
            $this->table(
                ['Item', 'Descrição', '→ Produto', 'SKU'],
                collect($linkable)->map(fn (array $row) => [
                    $row[0]->id,
                    $row[0]->description,
                    '#'.$row[1]->id.' '.mb_strimwidth($row[1]->name, 0, 40, '…'),
                    $row[1]->sku,
                ])->all(),
            );
        }

        foreach ($unmatched as $line) {
            $this->warn('Sem match: '.$line);
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
     * Código do cliente (normalizado, sem espaços) → nome do produto criado
     * pelo import da SQ-2026-00085.
     *
     * @return array<string, string>
     */
    protected function codeToNameMap(): array
    {
        $map = [
            'DPF-RACK-VER01' => 'Vertical Dumbbell Rack',
            'DPF-RACK-HOR01' => 'Dumbell Rack',
            'DPF-OBB-1.5' => '1.5m Olympic bearing bar',
            'DPF-OBB-W-1.2' => 'W - 1.2 meters Olympic bearing bar',
            'DPF-OBB-2.2' => '2.2m Olympic bearing bar',
        ];

        foreach (['2.5', '5', '10', '20'] as $kg) {
            $map["DPF-WP-{$kg}"] = "Weight plate {$kg}kg";
        }

        foreach (range(1, 10) as $kg) {
            $map["DPF-HEX-DB-{$kg}"] = "Dumbbell {$kg}kg";
        }

        foreach (['12.5', '15', '17.5', '20', '22.5', '25', '27.5', '30', '32.5', '35', '37.5', '40'] as $kg) {
            $map["DPF-RND-DB-{$kg}"] = "Dumbbell {$kg}kg";
        }

        return $map;
    }

    /**
     * Produtos referenciados pelas SQs da inquiry, agrupados por nome
     * normalizado. SQs rejeitadas ficam de fora: o import de uma versão
     * rejeitada pode ter criado produtos duplicados dos da versão vigente.
     *
     * @return Collection<string, Collection<int, Product>>
     */
    protected function sqProductPool(Inquiry $inquiry): Collection
    {
        $productIds = SupplierQuotationItem::query()
            ->whereHas('supplierQuotation', fn ($q) => $q
                ->where('inquiry_id', $inquiry->id)
                ->where('status', '!=', SupplierQuotationStatus::REJECTED->value))
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id');

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

    protected function normalizeCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }

    protected function normalizeName(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $name) ?? ''));
    }
}
