<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche o peso da caixa-mestre (seção "Embalagem" do produto) a partir do
 * peso líquido unitário já cadastrado em product_specifications.
 *
 * O backfill de 2026-09-03 (products:backfill-net-weight) encheu o líquido de
 * 1 peça, mas o cartão de embalagem continuou vazio: NW da caixa em branco em
 * todos os produtos dos embarques, e bruto em branco nos que só viajam em
 * pallet compartilhado (anilhas, halteres, barras, racks da Yangrun).
 *
 * Regras, na mesma linha do formulário do produto:
 *
 *   - NW da caixa = líquido unitário × pçs/caixa (pçs/caixa vazio conta 1).
 *   - Produto cujo nome traz o peso nominal ("Anilha 2,5 kg", "Dumbbell 12.5kg")
 *     e cujo líquido cadastrado bate com esse nominal é ferro fundido sem
 *     embalagem: bruto = líquido (regra do Gui, 2026-09-11). Nome com kg que
 *     NÃO bate com o líquido é suspeito e fica em aberto.
 *   - Cria a linha de embalagem quando não existe (1 pç/caixa).
 *   - Nunca sobrescreve valor já preenchido.
 *
 * Dry-run por padrão; passe --apply para gravar.
 */
class BackfillProductCartonWeightsCommand extends Command
{
    protected $signature = 'products:backfill-carton-weights
        {--shipment=* : Referências dos embarques cujos produtos devem ser cobertos (padrão: todo produto com líquido unitário)}
        {--apply : Grava as alterações (padrão: dry-run)}';

    protected $description = 'Preenche NW (e bruto de peso nominal) da caixa-mestre a partir do líquido unitário do produto';

    public function handle(): int
    {
        $products = $this->resolveProducts();

        if ($products->isEmpty()) {
            $this->error('Nenhum produto encontrado.');

            return self::FAILURE;
        }

        $rows = [];
        $writes = [];
        $skipped = [];
        $untouched = 0;

        foreach ($products as $product) {
            $unit = $product->specification?->net_weight;

            if ($unit === null || (float) $unit <= 0) {
                $skipped[] = $product;

                continue;
            }

            $packaging = $product->packaging;
            $pcs = max(1, (int) ($packaging?->pcs_per_carton ?? 1));
            $net = round((float) $unit * $pcs, 3);

            $attrs = [];

            if ($packaging === null || $packaging->carton_net_weight === null) {
                $attrs['carton_net_weight'] = $net;
            }

            if (($packaging === null || $packaging->carton_weight === null) && $this->isNominalWeight($product->name, (float) $unit)) {
                $attrs['carton_weight'] = $net;
            }

            if ($attrs === []) {
                $untouched++;

                continue;
            }

            if ($packaging === null) {
                $attrs['pcs_per_carton'] = 1;
            }

            $writes[$product->id] = $attrs;
            $rows[] = [
                $product->sku ?? $product->reference_code ?? '—',
                mb_substr($product->name, 0, 40),
                $pcs,
                number_format((float) $unit, 3),
                isset($attrs['carton_net_weight']) ? number_format($net, 3) : '(mantido)',
                isset($attrs['carton_weight']) ? number_format($net, 3).' = NW' : ($packaging?->carton_weight !== null ? '(mantido)' : '—'),
            ];
        }

        $this->newLine();

        if ($rows !== []) {
            $this->table(['SKU', 'Produto', 'Pçs/cx', 'Líquido/pç', 'NW caixa', 'Bruto caixa'], $rows);
        }

        $this->line(sprintf(
            'Produtos: %d — %d a preencher, %d já completos, %d sem líquido unitário.',
            $products->count(), count($writes), $untouched, count($skipped),
        ));

        foreach ($skipped as $product) {
            $this->line('  <error>sem líquido unitário:</error> '.($product->sku ?? $product->reference_code ?? '—').' — '.mb_substr($product->name, 0, 50));
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->comment('Dry-run — nada foi gravado. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($writes, $products) {
            foreach ($writes as $productId => $attrs) {
                $products->firstWhere('id', $productId)->packaging()->updateOrCreate([], $attrs);
            }
        });

        $this->newLine();
        $this->info(sprintf('✓ %d produto(s) com peso de caixa gravado.', count($writes)));

        return self::SUCCESS;
    }

    /**
     * O nome traz o peso nominal da peça ("2,5 kg", "12.5kg", "20 KG") e ele
     * bate com o líquido cadastrado — anilha, halter, kettlebell.
     */
    private function isNominalWeight(string $name, float $unitNet): bool
    {
        if (! preg_match_all('/(\d+(?:[.,]\d+)?)\s*kg\b/iu', $name, $m)) {
            return false;
        }

        foreach ($m[1] as $raw) {
            $nominal = (float) str_replace(',', '.', $raw);

            if ($nominal > 0 && abs($nominal - $unitNet) < 0.0005) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function resolveProducts()
    {
        $query = Product::query()->with(['specification', 'packaging']);

        $references = $this->option('shipment');

        if ($references === []) {
            return $query->whereHas('specification', fn ($q) => $q->whereNotNull('net_weight'))->get();
        }

        $shipmentIds = Shipment::whereIn('reference', $references)->pluck('id');

        $this->line('Embarques: <info>'.implode(', ', $references).'</info>');

        $productIds = ShipmentItem::whereIn('shipment_id', $shipmentIds)
            ->join('proforma_invoice_items', 'proforma_invoice_items.id', '=', 'shipment_items.proforma_invoice_item_id')
            ->whereNotNull('proforma_invoice_items.product_id')
            ->distinct()
            ->pluck('proforma_invoice_items.product_id');

        return $query->whereIn('id', $productIds)->orderBy('id')->get();
    }
}
