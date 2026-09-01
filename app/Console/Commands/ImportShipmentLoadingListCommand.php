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
use App\Domain\Logistics\Models\ShipmentPallet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Monta o packing list de um embarque a partir do loading list do fornecedor.
 *
 * O arquivo de entrada é um JSON normalizado, versionado em
 * database/data/loading-lists/. A conversão planilha → JSON é feita fora daqui,
 * para que o dado aplicado seja revisável no diff do git antes de tocar o banco.
 *
 * O contêiner pode declarar sua carga de duas formas:
 *
 *   lines     — atalho para quando 1 volume = 1 peça: modelo, quantos volumes e
 *               os pesos e cubagem unitários. Cada volume vira uma caixa.
 *   packages  — a lista explícita de volumes, para carga que não é 1:1: caixa
 *               com várias peças, pallet, ou volume com mais de um item dentro.
 *
 * O casamento é feito por REFERÊNCIA do embarque e, dentro dele, por
 * `reference_code` do produto ou pela descrição do item — nunca por id — para
 * que o mesmo arquivo e o mesmo comando produzam o mesmo resultado em dev e em
 * produção, onde os ids divergem.
 *
 * Antes de gravar qualquer coisa o comando exige que a carga feche: para cada
 * item, a soma das peças declaradas nos volumes tem de ser exatamente a
 * quantidade do item multiplicada pelo número de partes (1, ou o número de
 * partes quando o item está dividido em multi-box). Não fechando, aborta sem
 * escrever nada.
 *
 * Pallet segue a regra do domínio: conta 1 volume, o peso bruto é o dele, a
 * cubagem sai da caixa que ele carrega e o líquido nunca vem do estrado.
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

    protected $description = 'Monta as caixas, pallets e contêineres de um embarque a partir do loading list do fornecedor';

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
            $containers = $this->normalizeContainers($data);
            $plan = $this->buildPlan($shipment, $containers);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->renderPlan($shipment, $data, $containers, $plan);

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry-run — nada foi gravado. Rode de novo com --apply para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($shipment, $containers, $plan) {
            $this->wipeExistingPacking($shipment);
            $models = $this->syncContainers($shipment, $containers);
            $this->createPackages($shipment, $containers, $models, $plan['items']);

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
     * Põe os dois formatos de contêiner na mesma forma: uma lista de volumes,
     * cada um com tipo, pesos, cubagem e o que tem dentro.
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeContainers(array $data): array
    {
        $containers = [];

        foreach ($data['containers'] as $index => $container) {
            $packages = [];

            foreach ($container['packages'] ?? [] as $package) {
                $packages[] = [
                    'type' => ($package['type'] ?? 'carton') === 'pallet' ? 'pallet' : 'carton',
                    'gross_weight' => $package['gross_weight'] ?? null,
                    'net_weight' => $package['net_weight'] ?? null,
                    'volume' => $package['volume'] ?? null,
                    'notes' => $package['notes'] ?? null,
                    'contents' => array_map(
                        fn (array $c) => [
                            'ref' => $this->contentRef($c),
                            'pieces' => (int) ($c['pieces'] ?? 1),
                            'part' => $c['part'] ?? null,
                        ],
                        $package['contents'] ?? [],
                    ),
                ];
            }

            // Atalho `lines`: um volume por peça, todos iguais.
            foreach ($container['lines'] ?? [] as $line) {
                for ($i = 0; $i < (int) $line['packages']; $i++) {
                    $packages[] = [
                        'type' => 'carton',
                        'gross_weight' => $line['unit_gross_weight'] ?? null,
                        'net_weight' => $line['unit_net_weight'] ?? null,
                        'volume' => $line['unit_volume'] ?? null,
                        'notes' => $line['notes'] ?? null,
                        'contents' => [['ref' => $this->contentRef($line), 'pieces' => 1, 'part' => null]],
                    ];
                }
            }

            if ($packages === []) {
                throw new RuntimeException("Contêiner {$container['container_number']} não declara nem `lines` nem `packages`.");
            }

            // Volume sem conteúdo é caixa de acessório ou sobressalente: entra no
            // peso e na cubagem do contêiner mas não corresponde a linha nenhuma
            // da PI. Exige `notes` para nunca ser esquecimento.
            foreach ($packages as $package) {
                if ($package['contents'] === [] && ($package['notes'] ?? '') === '') {
                    throw new RuntimeException(
                        "Contêiner {$container['container_number']}: volume sem conteúdo e sem `notes` explicando o porquê."
                    );
                }
            }

            // array_merge e não `+`: o `+` manteria o `packages` cru do arquivo.
            $containers[] = array_merge($container, [
                'packages' => $packages,
                'sort_order' => $container['sort_order'] ?? $index + 1,
            ]);
        }

        return $containers;
    }

    /**
     * Como o arquivo aponta para um item do embarque: pelo `reference_code` do
     * produto, ou pela descrição do item quando o produto não tem código.
     *
     * @return array{by: string, value: string}
     */
    private function contentRef(array $node): array
    {
        if (($node['model'] ?? '') !== '') {
            return ['by' => 'model', 'value' => mb_strtoupper(trim((string) $node['model']))];
        }

        if (($node['description'] ?? '') !== '') {
            return ['by' => 'description', 'value' => mb_strtolower(trim((string) $node['description']))];
        }

        throw new RuntimeException('Conteúdo de volume sem `model` nem `description`.');
    }

    /**
     * Casa cada referência do arquivo com os itens do embarque e confere se a
     * carga fecha.
     *
     * Uma referência pode resolver para MAIS DE UM item: o Yinqian fatura o
     * mesmo modelo em duas invoices e o embarque fica com duas linhas idênticas
     * do mesmo produto. Nesse caso as peças são distribuídas na ordem dos itens
     * — enche o primeiro, depois o próximo — e a soma tem de fechar todos.
     */
    private function buildPlan(Shipment $shipment, array $containers): array
    {
        $byModel = [];
        $byDescription = [];

        foreach ($shipment->items->sortBy([['sort_order', 'asc'], ['id', 'asc']]) as $item) {
            $code = $item->proformaInvoiceItem?->product?->reference_code;

            if ($code !== null && $code !== '') {
                $byModel[mb_strtoupper($code)][] = $item;
            }

            $description = $item->proformaInvoiceItem?->description;

            if ($description !== null && $description !== '') {
                $byDescription[mb_strtolower(trim($description))][] = $item;
            }
        }

        // Peças e totais que o arquivo atribui a cada referência.
        $loaded = [];

        foreach ($containers as $container) {
            foreach ($container['packages'] as $package) {
                $single = count($package['contents']) === 1;

                foreach ($package['contents'] as $content) {
                    $key = $content['ref']['by'].':'.$content['ref']['value'];
                    $loaded[$key]['ref'] = $content['ref'];
                    $loaded[$key]['pieces'] = ($loaded[$key]['pieces'] ?? 0) + $content['pieces'];
                    $loaded[$key]['mixed'] = ($loaded[$key]['mixed'] ?? false) || ! $single;

                    if ($content['part'] !== null) {
                        $loaded[$key]['parts'][$content['part']] = ($loaded[$key]['parts'][$content['part']] ?? 0) + $content['pieces'];
                    } else {
                        $loaded[$key]['unnamed'] = ($loaded[$key]['unnamed'] ?? 0) + $content['pieces'];
                    }

                    if ($single) {
                        $loaded[$key]['gross'] = ($loaded[$key]['gross'] ?? 0.0) + (float) $package['gross_weight'];
                        $loaded[$key]['net'] = ($loaded[$key]['net'] ?? 0.0) + (float) $package['net_weight'];
                        $loaded[$key]['volume'] = ($loaded[$key]['volume'] ?? 0.0) + (float) $package['volume'];
                    }
                }
            }
        }

        $errors = [];
        $plan = [];
        $seen = [];

        foreach ($loaded as $key => $totals) {
            $ref = $totals['ref'];
            $label = $ref['value'];
            $candidates = $ref['by'] === 'model'
                ? ($byModel[$ref['value']] ?? [])
                : ($byDescription[$ref['value']] ?? []);

            if ($candidates === []) {
                $errors[] = $ref['by'] === 'model'
                    ? "{$label}: nenhum item do embarque aponta para um produto com este reference_code."
                    : "\"{$label}\": nenhum item do embarque tem esta descrição.";

                continue;
            }

            $slots = [];
            $expected = 0;
            $clash = false;

            foreach ($candidates as $item) {
                if (isset($seen[$item->id])) {
                    $errors[] = "{$label}: o item #{$item->id} já foi referenciado por outra chave do arquivo.";
                    $clash = true;

                    break;
                }

                $parts = $this->partLabels($item);
                $expected += (int) $item->quantity * count($parts);

                $slots[] = [
                    'item' => $item,
                    'parts' => $parts,
                    'set_id' => $item->packing_split['set_id'] ?? null,
                    'remaining' => array_fill_keys($parts, (int) $item->quantity),
                ];
            }

            if ($clash) {
                continue;
            }

            if ($totals['pieces'] !== $expected) {
                $errors[] = sprintf(
                    '%s: loading list traz %d peça(s), mas %s soma%s %d.',
                    $label,
                    $totals['pieces'],
                    count($slots) === 1
                        ? sprintf('o item #%d (%d × %d volume(s) por peça)', $slots[0]['item']->id, (int) $slots[0]['item']->quantity, count($slots[0]['parts']))
                        : count($slots).' itens do embarque ('.implode(' + ', array_map(fn (array $s) => '#'.$s['item']->id.'×'.(int) $s['item']->quantity, $slots)).')',
                    count($slots) === 1 ? '' : 'm',
                    $expected,
                );

                continue;
            }

            if ($error = $this->partsError($label, $totals, $slots)) {
                $errors[] = $error;

                continue;
            }

            foreach ($slots as $slot) {
                $seen[$slot['item']->id] = true;
            }

            $plan[$key] = ['slots' => $slots, 'totals' => $totals];
        }

        foreach ($shipment->items as $item) {
            if (! isset($seen[$item->id])) {
                $errors[] = sprintf(
                    'item #%d (%s) está no embarque mas não aparece em nenhum volume do arquivo.',
                    $item->id,
                    $item->proformaInvoiceItem?->description ?? '?',
                );
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("A carga não fecha com o embarque:\n  - ".implode("\n  - ", $errors));
        }

        return ['items' => $plan];
    }

    /**
     * Confere as partes que o arquivo nomeia explicitamente.
     *
     * Quando o fornecedor diz qual caixa é qual parte — a caixa M é o corpo, a Z
     * são os acessórios — o arquivo declara `part` no conteúdo e o rótulo vai
     * como veio. Nesse caso todos os volumes daquela referência têm de nomear a
     * parte, e cada parte tem de fechar a quantidade do item; senão o rótulo
     * sairia certo em uns volumes e chutado em outros.
     */
    private function partsError(string $label, array $totals, array $slots): ?string
    {
        $named = $totals['parts'] ?? [];

        if ($named === []) {
            return null;
        }

        $parts = $slots[0]['parts'];

        if ($parts === [null]) {
            return "{$label}: o arquivo nomeia partes, mas o item não está dividido em multi-box.";
        }

        if (($totals['unnamed'] ?? 0) > 0) {
            return "{$label}: {$totals['unnamed']} peça(s) sem `part` declarada, mas o resto do modelo declara.";
        }

        if ($unknown = array_diff(array_keys($named), $parts)) {
            return "{$label}: parte(s) desconhecida(s) ".implode(', ', $unknown).'; o item tem '.implode(', ', $parts).'.';
        }

        $quantity = array_sum(array_map(fn (array $s) => (int) $s['item']->quantity, $slots));

        foreach ($parts as $part) {
            if (($named[$part] ?? 0) !== $quantity) {
                return sprintf('%s: parte "%s" tem %d peça(s) no arquivo, mas o item soma %d.', $label, $part, $named[$part] ?? 0, $quantity);
            }
        }

        return null;
    }

    /**
     * Rótulos das partes de um item. Itens normais têm uma parte anônima; itens
     * divididos em multi-box têm um rótulo por caixa.
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

    private function wipeExistingPacking(Shipment $shipment): void
    {
        if ($this->option('keep-existing')) {
            return;
        }

        // carton_contents é cascadeOnDelete a partir de cartons; pallets ficam
        // órfãos se sobrarem, então saem junto.
        $shipment->cartons()->delete();
        $shipment->shipmentPallets()->delete();
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

            $model->fill(array_filter([
                'shipment_id' => $shipment->id,
                'label' => $container['label'],
                'container_number' => $number,
                'type' => $container['type'] ?? null,
                'seal_number' => $container['seal_number'] ?? null,
                'sort_order' => (int) $container['sort_order'],
            ], fn ($v) => $v !== null))->save();

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
     * Cria um volume por entrada do arquivo, na ordem dos contêineres.
     *
     * Caixa solta vira uma linha em `cartons`. Pallet vira um `shipment_pallets`
     * com o peso bruto declarado — que é o que vale nos totais — carregando uma
     * caixa com a cubagem e o peso líquido, porque o domínio tira a cubagem da
     * caixa quando o pallet não tem medida, e o líquido nunca vem do estrado.
     *
     * @param  list<array<string, mixed>>  $containers
     * @param  array<string, ShipmentContainer>  $containerModels
     * @param  array<string, array<string, mixed>>  $items
     */
    private function createPackages(Shipment $shipment, array $containers, array $containerModels, array &$items): void
    {
        $sequence = (int) ($shipment->cartons()->max('sort_order') ?? 0);
        $palletSequence = (int) ($shipment->shipmentPallets()->max('sort_order') ?? 0);
        $usedLabels = $shipment->cartons()->pluck('label')->flip();
        $usedPalletLabels = $shipment->shipmentPallets()->pluck('label')->flip();

        foreach ($containers as $container) {
            $containerModel = $containerModels[mb_strtoupper(trim((string) $container['container_number']))];

            foreach ($container['packages'] as $package) {
                $sequence++;

                while ($usedLabels->has($label = 'BOX-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT))) {
                    $sequence++;
                }

                $pallet = null;

                if ($package['type'] === 'pallet') {
                    $palletSequence++;

                    while ($usedPalletLabels->has($palletLabel = 'PLT-'.str_pad((string) $palletSequence, 3, '0', STR_PAD_LEFT))) {
                        $palletSequence++;
                    }

                    $pallet = ShipmentPallet::create([
                        'shipment_id' => $shipment->id,
                        'shipment_container_id' => $containerModel->id,
                        'label' => $palletLabel,
                        'gross_weight' => $package['gross_weight'],
                        'notes' => $package['notes'],
                        'sort_order' => $palletSequence,
                    ]);
                }

                $carton = Carton::create([
                    'shipment_id' => $shipment->id,
                    'shipment_container_id' => $containerModel->id,
                    'shipment_pallet_id' => $pallet?->id,
                    'label' => $label,
                    'packaging_type' => PackagingType::CARTON->value,
                    'net_weight' => $package['net_weight'],
                    'gross_weight' => $package['gross_weight'],
                    'volume' => $package['volume'],
                    'notes' => $pallet ? null : $package['notes'],
                    'sort_order' => $sequence,
                ]);

                $order = 0;

                foreach ($package['contents'] as $content) {
                    $key = $content['ref']['by'].':'.$content['ref']['value'];
                    $slots = &$items[$key]['slots'];

                    // Quando a referência cobre mais de um item do embarque, as
                    // peças do volume enchem um item de cada vez, na ordem.
                    $left = $content['pieces'];

                    foreach ($slots as &$slot) {
                        if ($left === 0) {
                            break;
                        }

                        $available = array_sum($slot['remaining']);

                        if ($available === 0) {
                            continue;
                        }

                        $take = min($left, $available);
                        $left -= $take;
                        $part = $this->nextPart($slot, $take, $content['part']);

                        CartonContent::create([
                            'carton_id' => $carton->id,
                            'shipment_item_id' => $slot['item']->id,
                            'pieces' => $take,
                            'part_label' => $part,
                            'multi_box_set_id' => $part === null ? null : $slot['set_id'],
                            'sort_order' => ++$order,
                        ]);
                    }

                    unset($slot, $slots);
                }
            }
        }
    }

    /**
     * Escolhe a parte do próximo volume de um item multi-box.
     *
     * O loading list diz quantos volumes de cada modelo foram para cada
     * contêiner, mas não diz quais partes — então distribuímos sempre pela
     * parte com mais volumes pendentes. Para um item de duas partes isso
     * intercala os volumes dentro de cada contêiner (corpo e acessórios viajam
     * juntos) e fecha exatamente a contagem de cada parte no fim.
     */
    private function nextPart(array &$slot, int $pieces, ?string $declared = null): ?string
    {
        if ($slot['parts'] === [null]) {
            $slot['remaining'][null] -= $pieces;

            return null;
        }

        $part = $declared ?? array_keys($slot['remaining'], max($slot['remaining']), true)[0];
        $slot['remaining'][$part] -= $pieces;

        return $part;
    }

    /**
     * Traz peso e cubagem dos itens do embarque para os números reais do
     * loading list, para que item e volume não se contradigam nos documentos.
     *
     * Só vale para item que viaja sozinho no volume. Num pallet com placas de
     * quatro pesos diferentes não há como repartir o peso do conjunto entre
     * elas sem inventar número, então esses ficam como estão.
     */
    private function syncItemWeights(array $plan): void
    {
        foreach ($plan['items'] as $entry) {
            // Referência que cobre dois itens não diz quanto do peso é de cada.
            if ($entry['totals']['mixed'] || count($entry['slots']) > 1) {
                continue;
            }

            $item = $entry['slots'][0]['item'];
            $quantity = max(1, (int) $item->quantity);

            $item->update([
                'unit_weight' => round($entry['totals']['gross'] / $quantity, 3),
                'total_weight' => round($entry['totals']['gross'], 3),
                'total_volume' => round($entry['totals']['volume'], 4),
            ]);
        }
    }

    // ───────────────────────────── relatório ─────────────────────────────

    private function renderPlan(Shipment $shipment, array $data, array $containers, array $plan): void
    {
        $this->line("Embarque: <info>{$shipment->reference}</info> (#{$shipment->id}, {$shipment->status->value})");
        $this->newLine();

        $rows = [];
        $totals = ['packages' => 0, 'pallets' => 0, 'gross' => 0.0, 'net' => 0.0, 'volume' => 0.0];

        foreach ($containers as $container) {
            $pallets = 0;
            $gross = 0.0;
            $net = 0.0;
            $volume = 0.0;

            foreach ($container['packages'] as $package) {
                $pallets += $package['type'] === 'pallet' ? 1 : 0;
                $gross += (float) $package['gross_weight'];
                $net += (float) $package['net_weight'];
                $volume += (float) $package['volume'];
            }

            $count = count($container['packages']);
            $declared = $container['declared'] ?? null;

            $rows[] = [
                $container['label'],
                $container['container_number'],
                $count.($pallets ? " ({$pallets} plt)" : ''),
                number_format($gross, 3),
                number_format($net, 3),
                number_format($volume, 3),
                $this->declaredCheck($declared, $count, $gross, $volume),
            ];

            $totals['packages'] += $count;
            $totals['pallets'] += $pallets;
            $totals['gross'] += $gross;
            $totals['net'] += $net;
            $totals['volume'] += $volume;
        }

        $rows[] = [
            '', '<info>TOTAL</info>',
            $totals['packages'].($totals['pallets'] ? " ({$totals['pallets']} plt)" : ''),
            number_format($totals['gross'], 3),
            number_format($totals['net'], 3),
            number_format($totals['volume'], 3),
            '',
        ];

        $this->table(['Rótulo', 'Contêiner', 'Volumes', 'GW (kg)', 'NW (kg)', 'CBM', 'vs declarado'], $rows);

        $existing = $shipment->cartons()->count();

        $this->line($this->option('keep-existing')
            ? "Caixas atuais: <comment>{$existing}</comment> (mantidas) → serão acrescentados {$totals['packages']} volumes."
            : "Caixas atuais: <comment>{$existing}</comment> (serão APAGADAS) → serão criados {$totals['packages']} volumes.");

        $this->line(sprintf(
            'Cabeçalho: %s volumes / %s CBM  →  %d volumes / %s CBM',
            $shipment->total_packages ?? '—',
            number_format((float) $shipment->total_volume, 3),
            $totals['packages'],
            number_format($totals['volume'], 3),
        ));

        foreach ($plan['items'] as $entry) {
            foreach ($entry['slots'] as $slot) {
                if ($slot['parts'] !== [null]) {
                    $this->line(sprintf(
                        'Multi-box %s: %d peças × %d partes (%s).',
                        $slot['item']->proformaInvoiceItem?->product?->reference_code ?? $slot['item']->id,
                        (int) $slot['item']->quantity,
                        count($slot['parts']),
                        implode('/', $slot['parts']),
                    ));
                }
            }

            if (count($entry['slots']) > 1) {
                $this->line(sprintf(
                    '%s: %d peças distribuídas entre %d itens do embarque (%s).',
                    $entry['totals']['ref']['value'],
                    $entry['totals']['pieces'],
                    count($entry['slots']),
                    implode(' + ', array_map(fn (array $s) => '#'.$s['item']->id.'×'.(int) $s['item']->quantity, $entry['slots'])),
                ));
            }
        }

        if (! $this->option('no-item-weights')) {
            $total = array_sum(array_map(fn (array $i) => count($i['slots']), $plan['items']));
            $syncable = 0;

            foreach ($plan['items'] as $entry) {
                if (! $entry['totals']['mixed'] && count($entry['slots']) === 1) {
                    $syncable++;
                }
            }

            $skipped = $total - $syncable;
            $this->line(sprintf(
                'Peso/cubagem dos itens: %d de %d serão atualizados%s.',
                $syncable,
                $total,
                $skipped ? " ({$skipped} viajam misturados no volume ou compartilham o modelo com outro item e ficam como estão)" : '',
            ));
        }

        foreach ($data['notes'] ?? [] as $note) {
            $this->line("<comment>Nota:</comment> {$note}");
        }
    }

    private function declaredCheck(?array $declared, int $packages, float $gross, float $volume): string
    {
        if ($declared === null) {
            return '—';
        }

        $problems = [];

        if (isset($declared['packages']) && (int) $declared['packages'] !== $packages) {
            $problems[] = 'volumes '.$declared['packages'];
        }

        if (isset($declared['gross_weight']) && abs((float) $declared['gross_weight'] - $gross) > 0.05) {
            $problems[] = 'GW '.number_format((float) $declared['gross_weight'], 3);
        }

        if (isset($declared['volume']) && abs((float) $declared['volume'] - $volume) > 0.005) {
            $problems[] = 'CBM '.number_format((float) $declared['volume'], 3);
        }

        return $problems === [] ? 'confere' : '<error>≠ '.implode(', ', $problems).'</error>';
    }
}
