<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logistics\Actions\AddContentToCartonAction;
use App\Domain\Logistics\Actions\CreateCartonAction;
use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Actions\SplitProductAcrossCartonsAction;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Divide um produto já empacotado em "unidade principal + small parts".
 *
 * Caso típico: o packing list tem N caixas com uma máquina cada, mas o BL
 * declara N + K volumes porque os acessórios de todas as máquinas viajaram
 * consolidados em K caixas à parte (SH-2026-00037: 70 esteiras + 5 caixas de
 * small parts = 75 volumes).
 *
 * O que faz, numa transação só:
 *  - define o split do item com as partes [--main-label, --parts-label];
 *  - marca todo conteúdo já existente do item como a parte principal;
 *  - cria --boxes caixas novas no container das caixas do item, com GW/NW/CBM
 *    por caixa, e reparte a quantidade do item entre elas como small parts
 *    (sobra vai para as primeiras) — assim as duas partes fecham completas.
 *
 * No packing list só a parte principal conta EQUIP QTY; as caixas de small
 * parts entram com bulto, peso e cubo.
 *
 * Dry-run por padrão: executa tudo dentro da transação, mostra o resultado e
 * desfaz. --apply grava. Rodar de novo depois de aplicado não faz nada.
 */
class SplitSmallPartsCommand extends Command
{
    protected $signature = 'shipments:split-small-parts
        {reference : Referência do embarque, ex.: SH-2026-00037}
        {--item= : Id do shipment item (obrigatório se o embarque tiver mais de um)}
        {--main-label= : Nome da parte principal, ex.: Treadmill}
        {--parts-label=Small Parts : Nome da parte dos acessórios}
        {--boxes= : Quantas caixas de small parts}
        {--gross= : GW de cada caixa de small parts, em kg}
        {--net= : NW de cada caixa, em kg (vazio = 90% do GW, como no builder)}
        {--volume= : CBM de cada caixa, em m³}
        {--apply : Grava (senão, dry-run)}';

    protected $description = 'Split a packed product into main unit + consolidated small-parts boxes';

    public function handle(): int
    {
        $shipment = Shipment::where('reference', $this->argument('reference'))->first();

        if (! $shipment) {
            $this->error('Embarque não encontrado: '.$this->argument('reference'));

            return self::FAILURE;
        }

        $mainLabel = trim((string) $this->option('main-label'));
        $partsLabel = trim((string) $this->option('parts-label'));
        $boxes = (int) $this->option('boxes');
        $gross = (float) $this->option('gross');
        $net = $this->option('net') !== null ? (float) $this->option('net') : round($gross * 0.9, 3);
        $volume = (float) $this->option('volume');

        if ($mainLabel === '' || $partsLabel === '' || $mainLabel === $partsLabel) {
            $this->error('Informe --main-label, diferente de --parts-label.');

            return self::FAILURE;
        }

        if ($boxes < 1 || $gross <= 0 || $volume <= 0) {
            $this->error('Informe --boxes, --gross e --volume maiores que zero.');

            return self::FAILURE;
        }

        try {
            $item = $this->resolveItem($shipment);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (($item->packing_split['part_labels'] ?? null) === [$mainLabel, $partsLabel]) {
            $this->info("Split {$mainLabel} / {$partsLabel} já aplicado no item {$item->id} — nada a fazer.");

            return self::SUCCESS;
        }

        $before = $this->snapshot($shipment);
        $apply = (bool) $this->option('apply');

        DB::beginTransaction();

        try {
            $created = $this->split($shipment, $item, $mainLabel, $partsLabel, $boxes, $gross, $net, $volume);
            $after = $this->snapshot($shipment->fresh());

            $apply ? DB::commit() : DB::rollBack();
        } catch (RuntimeException $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("{$shipment->reference} · item {$item->id} · {$item->product_name}");
        $this->line("Split: {$mainLabel} / {$partsLabel} · caixas novas: ".implode(', ', $created));
        $this->line(sprintf('Cada caixa de %s: GW %.3f kg · NW %.3f kg · %.6f m³', $partsLabel, $gross, $net, $volume));
        $this->table(['', 'Caixas', 'Volumes', 'GW (kg)', 'NW (kg)', 'CBM'], [
            ['antes', ...$before],
            ['depois', ...$after],
        ]);
        $this->line($apply ? 'Aplicado.' : 'Dry-run: nada foi gravado. Rode com --apply para gravar.');

        return self::SUCCESS;
    }

    private function resolveItem(Shipment $shipment): ShipmentItem
    {
        $items = $shipment->items()->get();

        if ($this->option('item')) {
            $item = $items->firstWhere('id', (int) $this->option('item'));

            if (! $item) {
                throw new RuntimeException('Item '.$this->option('item').' não pertence a '.$shipment->reference.'.');
            }

            return $item;
        }

        if ($items->count() !== 1) {
            throw new RuntimeException($shipment->reference.' tem '.$items->count().' itens — informe --item.');
        }

        return $items->first();
    }

    /**
     * @return array<int, string> labels das caixas criadas
     */
    private function split(
        Shipment $shipment,
        ShipmentItem $item,
        string $mainLabel,
        string $partsLabel,
        int $boxes,
        float $gross,
        float $net,
        float $volume,
    ): array {
        if ($item->packing_split !== null) {
            throw new RuntimeException("O item {$item->id} já tem outro split (".implode(' / ', $item->packing_split['part_labels'] ?? []).') — limpe no builder antes.');
        }

        $contents = CartonContent::with('carton')->where('shipment_item_id', $item->id)->get();
        $quantity = (int) $item->quantity;

        // As caixas atuais viram a parte principal; se não somam o item inteiro,
        // o split nasceria com a parte principal incompleta.
        if ((int) $contents->sum('pieces') !== $quantity) {
            throw new RuntimeException(sprintf(
                'O item %d tem %d peças empacotadas para %d no embarque — complete o packing list antes de dividir.',
                $item->id,
                (int) $contents->sum('pieces'),
                $quantity,
            ));
        }

        if ($boxes > $quantity) {
            throw new RuntimeException("--boxes ({$boxes}) maior que a quantidade do item ({$quantity}).");
        }

        $definition = app(SplitProductAcrossCartonsAction::class)->execute($item, [$mainLabel, $partsLabel]);
        $setId = $definition['set_id'];

        CartonContent::whereIn('id', $contents->pluck('id'))->update([
            'part_label' => $mainLabel,
            'multi_box_set_id' => $setId,
        ]);

        // As caixas de small parts vão para o container da última caixa do item.
        $containerId = $contents->sortBy(fn ($c) => [$c->carton->sort_order, $c->carton->label])
            ->last()?->carton?->shipment_container_id;

        $item->refresh();
        $createCarton = app(CreateCartonAction::class);
        $addContent = app(AddContentToCartonAction::class);
        $created = [];

        foreach ($this->distribute($quantity, $boxes) as $pieces) {
            $carton = $createCarton->execute($shipment, [
                'shipment_container_id' => $containerId,
                'gross_weight' => $gross,
                'net_weight' => $net,
                'volume' => $volume,
            ]);

            $addContent->execute($carton, $item, $pieces, $partsLabel, $setId);
            $created[] = $carton->label;
        }

        app(RecalculateShipmentTotalsAction::class)->execute($shipment->fresh());

        return $created;
    }

    /**
     * Reparte $quantity em $boxes partes; a sobra vai para as primeiras.
     *
     * @return array<int, int>
     */
    private function distribute(int $quantity, int $boxes): array
    {
        $base = intdiv($quantity, $boxes);
        $remainder = $quantity % $boxes;

        return array_map(fn (int $i) => $base + ($i < $remainder ? 1 : 0), range(0, $boxes - 1));
    }

    /**
     * @return array<int, string>
     */
    private function snapshot(Shipment $shipment): array
    {
        return [
            (string) $shipment->cartons()->count(),
            (string) $shipment->total_packages,
            number_format((float) $shipment->total_gross_weight, 2),
            number_format((float) $shipment->total_net_weight, 2),
            number_format((float) $shipment->total_volume, 4),
        ];
    }
}
