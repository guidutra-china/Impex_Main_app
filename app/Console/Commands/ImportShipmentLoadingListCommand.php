<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Logistics\Actions\RecalculateShipmentTotalsAction;
use App\Domain\Logistics\Actions\SyncShipmentContainerNumbersAction;
use App\Domain\Logistics\Enums\PackagingType;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentContainer;
use App\Domain\Logistics\Models\ShipmentItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monta o packing list de um embarque a partir do loading list do fornecedor.
 *
 * O arquivo de entrada é um JSON normalizado, versionado em
 * database/data/loading-lists/, com um bloco por contêiner e uma linha por
 * modelo carregado (quantidade de volumes + peso líquido, peso bruto e cubagem
 * unitários). O formato é o mesmo que o fornecedor manda na planilha; a
 * conversão planilha → JSON é feita fora daqui, para que o dado aplicado seja
 * revisável no diff do git antes de tocar o banco.
 *
 * O casamento é feito por REFERÊNCIA do embarque e por `reference_code` do
 * produto — nunca por id — para que o mesmo arquivo e o mesmo comando produzam
 * o mesmo resultado em dev e em produção, onde os ids divergem.
 *
 * Antes de gravar qualquer coisa o comando exige que a carga feche: para cada
 * modelo, a soma dos volumes declarados nos contêineres tem de ser exatamente
 * a quantidade do item do embarque multiplicada pelo número de volumes por
 * peça (1, ou o número de partes quando o item está dividido em multi-box).
 * Não fechando, aborta sem escrever nada.
 *
 * Dry-run por padrão; passe --apply para gravar.
 */
class ImportShipmentLoadingListCommand extends Command
{
    protected $signature = 'shipments:import-loading-list
        {file : JSON do loading list (nome dentro de database/data/loading-lists ou caminho completo)}
        {--shipment= : Referência do embarque, sobrescrevendo a declarada no arquivo}
        {--expect-cartons= : Aborta se o embarque não tiver exatamente esta quantidade de caixas antes de começar}
        {--keep-existing : Mantém as caixas atuais e apenas acrescenta as do arquivo}
        {--no-item-weights : Não atualiza peso e cubagem dos itens do embarque}
        {--apply : Grava as alterações (padrão: dry-run)}';

    protected $description = 'Monta as caixas e contêineres de um embarque a partir do loading list do fornecedor';

    public function __construct(
        private readonly RecalculateShipmentTotalsAction $recalculateTotals,
        private readonly SyncShipmentContainerNumbersAction $syncContainerNumbers,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        try {
            $data = $this->readFile((string) $this->argument('file'));
            $shipment = $this->resolveShipment($data);
            $this->guardExistingCartons($shipment);
            $plan = $this->buildPlan($shipment, $data);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderPlan($shipment, $data, $plan);

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry-run — nada foi gravado. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($shipment, $data, $plan) {
            $this->wipeExistingCartons($shipment);
            $containers = $this->syncContainers($shipment, $data['containers']);
            $this->createCartons($shipment, $data['containers'], $containers, $plan['items']);

            if (! $this->option('no-item-weights')) {
                $this->syncItemWeights($plan);
            }

            $this->syncContainerNumbers->execute($shipment->refresh());
            $this->recalculateTotals->execute($shipment->refresh());
        });

        $shipment->refresh();

        $this->newLine();
        $this->info(sprintf(
            '✓ %s: %d volumes, GW %s kg, NW %s kg, %s CBM, contêineres %s',
            $shipment->reference,
            (int) $shipment->total_packages,
            number_format((float) $shipment->total_gross_weight, 3),
            number_format((float) $shipment->total_net_weight, 3),
            number_format((float) $shipment->total_volume, 3),
            $shipment->container_number,
        ));

        return self::SUCCESS;
    }

    // ───────────────────────── leitura e validação ─────────────────────────

    private function readFile(string $file): array
    {
        $path = str_contains($file, '/')
            ? $file
            : database_path('data/loading-lists/'.$file);

        if (! str_ends_with($path, '.json')) {
            $path .= '.json';
        }

        if (! is_file($path)) {
            throw new RuntimeException("Arquivo não encontrado: {$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data) || ! is_array($data['containers'] ?? null) || $data['containers'] === []) {
            throw new RuntimeException("JSON inválido ou sem contêineres: {$path}");
        }

        $this->line("Arquivo: <info>{$path}</info>");

        return $data;
    }

    private function resolveShipment(array $data): Shipment
    {
        $reference = (string) ($this->option('shipment') ?: ($data['shipment'] ?? ''));

        if ($reference === '') {
            throw new RuntimeException('O arquivo não declara o embarque; informe --shipment=SH-....');
        }

        $shipment = Shipment::query()
            ->where('reference', $reference)
            ->with(['items.proformaInvoiceItem.product:id,reference_code,name'])
            ->first();

        if (! $shipment) {
            throw new RuntimeException("Embarque não encontrado: {$reference}");
        }

        return $shipment;
    }

    /**
     * Trava de segurança para a execução em produção: o operador declara quantas
     * caixas espera encontrar, e o comando se recusa a apagar um estado diferente
     * do que ele conferiu.
     */
    private function guardExistingCartons(Shipment $shipment): void
    {
        $expected = $this->option('expect-cartons');

        if ($expected === null) {
            return;
        }

        $actual = $shipment->cartons()->count();

        if ($actual !== (int) $expected) {
            throw new RuntimeException(sprintf(
                '%s tem %d caixas, mas --expect-cartons=%d. Confira o estado antes de rodar.',
                $shipment->reference,
                $actual,
                (int) $expected,
            ));
        }
    }

    /**
     * Casa cada modelo do arquivo com o item do embarque e confere se a carga
     * fecha. Devolve, por item, o mapa de partes (multi-box) e os totais que o
     * loading list atribui a ele.
     */
    private function buildPlan(Shipment $shipment, array $data): array
    {
        $itemsByModel = [];

        foreach ($shipment->items as $item) {
            $code = $item->proformaInvoiceItem?->product?->reference_code;

            if ($code === null || $code === '') {
                continue;
            }

            $itemsByModel[mb_strtoupper($code)][] = $item;
        }

        $loaded = [];

        foreach ($data['containers'] as $container) {
            foreach ($container['lines'] as $line) {
                $model = mb_strtoupper((string) $line['model']);
                $loaded[$model]['packages'] = ($loaded[$model]['packages'] ?? 0) + (int) $line['packages'];
                $loaded[$model]['gross'] = ($loaded[$model]['gross'] ?? 0.0) + $line['packages'] * (float) $line['unit_gross_weight'];
                $loaded[$model]['net'] = ($loaded[$model]['net'] ?? 0.0) + $line['packages'] * (float) $line['unit_net_weight'];
                $loaded[$model]['volume'] = ($loaded[$model]['volume'] ?? 0.0) + $line['packages'] * (float) $line['unit_volume'];
            }
        }

        $errors = [];
        $items = [];

        foreach ($loaded as $model => $totals) {
            $candidates = $itemsByModel[$model] ?? [];

            if ($candidates === []) {
                $errors[] = "{$model}: nenhum item do embarque aponta para um produto com este reference_code.";

                continue;
            }

            if (count($candidates) > 1) {
                $errors[] = "{$model}: {$shipment->reference} tem ".count($candidates).' itens para este produto — ambíguo.';

                continue;
            }

            $item = $candidates[0];
            $parts = $this->partLabels($item);
            $expected = (int) $item->quantity * count($parts);

            if ($totals['packages'] !== $expected) {
                $errors[] = sprintf(
                    '%s: loading list traz %d volumes, mas o item #%d tem %d peça(s) × %d volume(s) por peça = %d.',
                    $model,
                    $totals['packages'],
                    $item->id,
                    (int) $item->quantity,
                    count($parts),
                    $expected,
                );

                continue;
            }

            $items[$model] = [
                'item' => $item,
                'parts' => $parts,
                'set_id' => $item->packing_split['set_id'] ?? null,
                'remaining' => array_fill_keys($parts, (int) $item->quantity),
                'totals' => $totals,
            ];
        }

        foreach ($itemsByModel as $model => $candidates) {
            if (! isset($loaded[$model])) {
                $errors[] = "{$model}: item #{$candidates[0]->id} está no embarque mas não aparece em nenhum contêiner do arquivo.";
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("A carga não fecha com o embarque:\n  - ".implode("\n  - ", $errors));
        }

        return ['items' => $items];
    }

    /**
     * Rótulos das partes de um item. Itens normais têm uma parte anônima (uma
     * caixa por peça); itens divididos em multi-box têm um rótulo por caixa.
     *
     * @return list<string|null>
     */
    private function partLabels(ShipmentItem $item): array
    {
        $labels = $item->packing_split['part_labels'] ?? null;

        return is_array($labels) && $labels !== []
            ? array_values($labels)
            : [null];
    }

    // ───────────────────────────── escrita ─────────────────────────────

    private function wipeExistingCartons(Shipment $shipment): void
    {
        if ($this->option('keep-existing')) {
            return;
        }

        // carton_contents é cascadeOnDelete a partir de cartons.
        $shipment->cartons()->delete();
    }

    /**
     * @param  list<array<string, mixed>>  $containers
     * @return array<string, ShipmentContainer> indexado pelo número do contêiner
     */
    private function syncContainers(Shipment $shipment, array $containers): array
    {
        $synced = [];

        // (shipment_id, label) é único, e o arquivo pode renumerar contêineres
        // que já existem — CONT-001 virar CONT-003 e vice-versa. Solta todos os
        // rótulos atuais primeiro para que a renumeração não colida no meio.
        $shipment->shipmentContainers()->each(
            fn (ShipmentContainer $c) => $c->update(['label' => 'TMP-'.$c->id])
        );

        foreach ($containers as $container) {
            $number = mb_strtoupper(trim((string) $container['container_number']));

            $model = $shipment->shipmentContainers()
                ->whereRaw('UPPER(container_number) = ?', [$number])
                ->first() ?? new ShipmentContainer(['shipment_id' => $shipment->id]);

            $model->fill([
                'shipment_id' => $shipment->id,
                'label' => $container['label'],
                'container_number' => $number,
                'type' => $container['type'] ?? null,
                'sort_order' => (int) ($container['sort_order'] ?? 0),
            ])->save();

            $synced[$number] = $model;
        }

        // Contêineres que sobraram de um estado anterior e ficaram vazios.
        $shipment->shipmentContainers()
            ->whereNotIn('id', array_map(fn (ShipmentContainer $c) => $c->id, $synced))
            ->whereDoesntHave('cartons')
            ->whereDoesntHave('pallets')
            ->delete();

        return $synced;
    }

    /**
     * Cria uma caixa por volume, na ordem dos contêineres e das linhas do
     * arquivo, com os pesos e a cubagem unitários do fornecedor.
     *
     * @param  list<array<string, mixed>>  $containers
     * @param  array<string, ShipmentContainer>  $containerModels
     * @param  array<string, array<string, mixed>>  $items
     */
    private function createCartons(Shipment $shipment, array $containers, array $containerModels, array &$items): void
    {
        $sequence = (int) ($shipment->cartons()->max('sort_order') ?? 0);
        $usedLabels = $shipment->cartons()->pluck('label')->flip();

        foreach ($containers as $container) {
            $containerModel = $containerModels[mb_strtoupper(trim((string) $container['container_number']))];

            foreach ($container['lines'] as $line) {
                $model = mb_strtoupper((string) $line['model']);
                $plan = &$items[$model];

                for ($i = 0; $i < (int) $line['packages']; $i++) {
                    $sequence++;

                    while ($usedLabels->has($label = 'BOX-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT))) {
                        $sequence++;
                    }

                    $carton = Carton::create([
                        'shipment_id' => $shipment->id,
                        'shipment_container_id' => $containerModel->id,
                        'label' => $label,
                        'packaging_type' => PackagingType::CARTON->value,
                        'net_weight' => $line['unit_net_weight'],
                        'gross_weight' => $line['unit_gross_weight'],
                        'volume' => $line['unit_volume'],
                        'notes' => $line['notes'] ?? null,
                        'sort_order' => $sequence,
                    ]);

                    $part = $this->nextPart($plan);

                    CartonContent::create([
                        'carton_id' => $carton->id,
                        'shipment_item_id' => $plan['item']->id,
                        'pieces' => 1,
                        'part_label' => $part,
                        'multi_box_set_id' => $part === null ? null : $plan['set_id'],
                        'sort_order' => 1,
                    ]);
                }

                unset($plan);
            }
        }
    }

    /**
     * Escolhe a parte da próxima caixa de um item multi-box.
     *
     * O loading list diz quantos volumes de cada modelo foram para cada
     * contêiner, mas não diz quais partes — então distribuímos sempre pela
     * parte com mais volumes pendentes. Para um item de duas partes isso
     * intercala as caixas dentro de cada contêiner (corpo e acessórios viajam
     * juntos) e fecha exatamente a contagem de cada parte no fim.
     */
    private function nextPart(array &$plan): ?string
    {
        if ($plan['parts'] === [null]) {
            return null;
        }

        $part = array_keys($plan['remaining'], max($plan['remaining']), true)[0];
        $plan['remaining'][$part]--;

        return $part;
    }

    /**
     * Traz peso e cubagem dos itens do embarque para os números reais do
     * loading list, para que item e caixa não se contradigam nos documentos.
     */
    private function syncItemWeights(array $plan): void
    {
        foreach ($plan['items'] as $entry) {
            $item = $entry['item'];
            $quantity = max(1, (int) $item->quantity);

            $item->update([
                'unit_weight' => round($entry['totals']['gross'] / $quantity, 3),
                'total_weight' => round($entry['totals']['gross'], 3),
                'total_volume' => round($entry['totals']['volume'], 4),
            ]);
        }
    }

    // ───────────────────────────── relatório ─────────────────────────────

    private function renderPlan(Shipment $shipment, array $data, array $plan): void
    {
        $this->line("Embarque: <info>{$shipment->reference}</info> (#{$shipment->id}, {$shipment->status->value})");
        $this->newLine();

        $rows = [];
        $packages = 0;
        $gross = 0.0;
        $net = 0.0;
        $volume = 0.0;

        foreach ($data['containers'] as $container) {
            $declared = $container['declared'];
            $packages += (int) $declared['packages'];
            $gross += (float) $declared['gross_weight'];
            $net += (float) $declared['net_weight'];
            $volume += (float) $declared['volume'];

            $rows[] = [
                $container['label'],
                $container['container_number'],
                $container['type'] ?? '—',
                count($container['lines']),
                $declared['packages'],
                number_format((float) $declared['gross_weight'], 3),
                number_format((float) $declared['net_weight'], 3),
                number_format((float) $declared['volume'], 3),
            ];
        }

        $rows[] = ['', '<info>TOTAL</info>', '', '', $packages, number_format($gross, 3), number_format($net, 3), number_format($volume, 3)];

        $this->table(['Rótulo', 'Contêiner', 'Tipo', 'Linhas', 'Volumes', 'GW (kg)', 'NW (kg)', 'CBM'], $rows);

        $existing = $shipment->cartons()->count();

        $this->line($this->option('keep-existing')
            ? "Caixas atuais: <comment>{$existing}</comment> (mantidas) → serão acrescentadas {$packages}."
            : "Caixas atuais: <comment>{$existing}</comment> (serão APAGADAS) → serão criadas {$packages}.");

        $this->line(sprintf(
            'Cabeçalho: %d volumes / %s CBM  →  %d volumes / %s CBM',
            (int) $shipment->total_packages,
            number_format((float) $shipment->total_volume, 3),
            $packages,
            number_format($volume, 3),
        ));

        $split = array_filter($plan['items'], fn (array $i) => $i['parts'] !== [null]);

        foreach ($split as $model => $entry) {
            $this->line(sprintf(
                'Multi-box %s: %d peças × %d partes (%s) = %d volumes.',
                $model,
                (int) $entry['item']->quantity,
                count($entry['parts']),
                implode('/', $entry['parts']),
                $entry['totals']['packages'],
            ));
        }

        if (! $this->option('no-item-weights')) {
            $changed = 0;

            foreach ($plan['items'] as $entry) {
                $quantity = max(1, (int) $entry['item']->quantity);
                $unit = round($entry['totals']['gross'] / $quantity, 3);

                if (abs($unit - (float) $entry['item']->unit_weight) > 0.001) {
                    $changed++;
                }
            }

            $this->line("Peso/cubagem dos itens: {$changed} de ".count($plan['items']).' serão atualizados para os números do loading list.');
        }

        foreach ($data['notes'] ?? [] as $note) {
            $this->line("<comment>Nota:</comment> {$note}");
        }
    }
}
