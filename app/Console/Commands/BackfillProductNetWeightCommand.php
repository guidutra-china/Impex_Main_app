<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Preenche o peso líquido de 1 peça dos produtos a partir do que já foi embarcado.
 *
 * O packing list de um embarque guarda peso líquido e bruto por VOLUME. Quando o
 * volume leva um produto só, dividir pelo número de peças dá o líquido unitário
 * com a precisão do documento do fornecedor — melhor que qualquer estimativa. Em
 * volume compartilhado não há como repartir o peso entre os produtos sem
 * inventar, então esses caem na ordem abaixo:
 *
 *   1. volume de um produto só, do próprio embarque         (exato)
 *   2. valor declarado em database/data/product-net-weights.json
 *   3. 90% do bruto unitário, também do embarque            (convenção da casa)
 *   4. 90% do bruto do cadastro de embalagem                (convenção da casa)
 *
 * O declarado vem antes dos 90% de propósito: número conferido à mão vale mais
 * que estimativa. E o bruto do cadastro é o último porque é o menos confiável —
 * nos embarques SH-48/49/50/52 ele estava desatualizado em 12 produtos, a ponto
 * de declarar bruto MENOR que o líquido real do fornecedor.
 *
 * Item dividido em multi-box conta as partes: as duas caixas de uma esteira são
 * uma peça só, então o líquido da peça é a soma das partes.
 *
 * Nunca sobrescreve um peso já preenchido — passe --overwrite para isso.
 * Dry-run por padrão; passe --apply para gravar.
 */
class BackfillProductNetWeightCommand extends Command
{
    protected $signature = 'products:backfill-net-weight
        {--shipment=* : Referências dos embarques de onde tirar o peso (padrão: todos os que têm packing list)}
        {--overwrite : Também recalcula produtos que já têm peso líquido}
        {--apply : Grava as alterações (padrão: dry-run)}';

    protected $description = 'Preenche o peso líquido unitário dos produtos a partir dos volumes já embarcados';

    private const OVERRIDES = 'data/product-net-weights.json';

    public function handle(): int
    {
        $shipments = $this->resolveShipments();

        if ($shipments->isEmpty()) {
            $this->error('Nenhum embarque com packing list encontrado.');

            return self::FAILURE;
        }

        $this->line('Embarques: <info>'.$shipments->pluck('reference')->implode(', ').'</info>');

        $derived = $this->deriveFromCartons($shipments->pluck('id')->all());
        $overrides = $this->readOverrides();

        $rows = [];
        $writes = [];
        $unresolved = [];

        foreach ($derived as $productId => $d) {
            $product = $d['product'];
            $current = $product->specification?->net_weight;

            if ($current !== null && (float) $current > 0 && ! $this->option('overwrite')) {
                continue;
            }

            [$net, $source] = $this->pick($d, $overrides[$product->sku] ?? null, $product);

            if ($net === null) {
                $unresolved[] = $product;

                continue;
            }

            $rows[] = [$product->sku ?? '—', mb_substr($product->name, 0, 44), $current ?? '—', number_format($net, 3), $source];
            $writes[$productId] = $net;
        }

        $this->renderPlan($rows, $unresolved, $derived);

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry-run — nada foi gravado. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($writes, $derived) {
            foreach ($writes as $productId => $net) {
                $derived[$productId]['product']->specification()->updateOrCreate([], ['net_weight' => $net]);
            }
        });

        $this->newLine();
        $this->info(sprintf('✓ %d produto(s) com peso líquido gravado.', count($writes)));

        return $unresolved === [] ? self::SUCCESS : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Shipment>
     */
    private function resolveShipments()
    {
        $references = $this->option('shipment');

        $query = Shipment::query()->whereHas('cartons');

        if ($references !== []) {
            $query->whereIn('reference', $references);
        }

        return $query->get(['id', 'reference']);
    }

    /**
     * Soma, por produto, o líquido e o bruto dos volumes que levam só ele — e
     * separa as peças que só viajam em volume compartilhado.
     *
     * @param  list<int>  $shipmentIds
     * @return array<int, array<string, mixed>>
     */
    private function deriveFromCartons(array $shipmentIds): array
    {
        $out = [];

        Carton::whereIn('shipment_id', $shipmentIds)
            ->with(['contents.shipmentItem.proformaInvoiceItem.product.specification',
                'contents.shipmentItem.proformaInvoiceItem.product.packaging'])
            ->chunkById(500, function ($cartons) use (&$out) {
                foreach ($cartons as $carton) {
                    $alone = $carton->contents->count() === 1;

                    foreach ($carton->contents as $content) {
                        $item = $content->shipmentItem;
                        $product = $item?->proformaInvoiceItem?->product;

                        if (! $product) {
                            continue;
                        }

                        $labels = $item->packing_split['part_labels'] ?? null;
                        $parts = is_array($labels) && $labels !== [] ? count($labels) : 1;

                        $out[$product->id]['product'] = $product;
                        $out[$product->id]['parts'] = max($out[$product->id]['parts'] ?? 1, $parts);
                        $out[$product->id]['pieces'] = ($out[$product->id]['pieces'] ?? 0) + (int) $content->pieces;

                        if (! $alone) {
                            $out[$product->id]['shared'] = true;

                            continue;
                        }

                        $out[$product->id]['solo_pieces'] = ($out[$product->id]['solo_pieces'] ?? 0) + (int) $content->pieces;
                        $out[$product->id]['solo_net'] = ($out[$product->id]['solo_net'] ?? 0.0) + (float) $carton->net_weight;
                        $out[$product->id]['solo_gross'] = ($out[$product->id]['solo_gross'] ?? 0.0) + (float) $carton->gross_weight;
                    }
                }
            });

        return $out;
    }

    /**
     * @return array{0: float|null, 1: string}
     */
    private function pick(array $derived, ?float $override, Product $product): array
    {
        $pieces = $derived['solo_pieces'] ?? 0;
        $parts = $derived['parts'];

        if ($pieces > 0 && ($derived['solo_net'] ?? 0.0) > 0) {
            return [round($derived['solo_net'] / $pieces * $parts, 3), 'embarque'];
        }

        // Valor declarado vale mais que estimativa: entra antes dos 90%.
        if ($override !== null) {
            return [round($override, 3), 'declarado no arquivo'];
        }

        if ($pieces > 0 && ($derived['solo_gross'] ?? 0.0) > 0) {
            return [round($derived['solo_gross'] / $pieces * $parts * 0.9, 3), '90% do bruto embarcado'];
        }

        $packaging = $product->packaging;

        if ($packaging && (float) $packaging->carton_weight > 0 && (int) $packaging->pcs_per_carton > 0) {
            return [round((float) $packaging->carton_weight / (int) $packaging->pcs_per_carton * 0.9, 3), '90% do bruto do cadastro'];
        }

        return [null, ''];
    }

    /**
     * Pesos declarados à mão, para produto que só viaja em volume compartilhado
     * e não tem bruto em lugar nenhum.
     *
     * @return array<string, float>
     */
    private function readOverrides(): array
    {
        $path = database_path(self::OVERRIDES);

        if (! is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['net_weights'] ?? null)) {
            throw new RuntimeException("JSON inválido: {$path}");
        }

        $this->line("Declarados à mão: <info>{$path}</info>");

        return array_map(fn ($v) => (float) $v, $data['net_weights']);
    }

    /**
     * @param  list<array<int, string>>  $rows
     * @param  list<Product>  $unresolved
     */
    private function renderPlan(array $rows, array $unresolved, array $derived): void
    {
        $this->newLine();

        if ($rows !== []) {
            $this->table(['SKU', 'Produto', 'Atual', 'Líquido/pç', 'Origem'], $rows);
        }

        $bySource = [];

        foreach ($rows as $row) {
            $bySource[$row[4]] = ($bySource[$row[4]] ?? 0) + 1;
        }

        foreach ($bySource as $source => $count) {
            $this->line("  {$count} por {$source}");
        }

        $already = count($derived) - count($rows) - count($unresolved);
        $this->line(sprintf(
            'Produtos nos embarques: %d — %d a preencher, %d já preenchidos, %d sem base.',
            count($derived), count($rows), $already, count($unresolved),
        ));

        foreach ($unresolved as $product) {
            $this->line("  <error>sem base:</error> {$product->sku} — ".mb_substr($product->name, 0, 50));
        }
    }
}
