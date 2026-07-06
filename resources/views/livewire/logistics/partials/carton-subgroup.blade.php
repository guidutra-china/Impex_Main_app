{{--
    Renders one subgroup of cartons sharing the same content signature.
    Collapsed by default, with its own expand toggle and "Delete all" scoped
    to the subgroup.

    Props:
      - $signature : string  (CartonGroupingService::SIGNATURE_MIXED for mixed boxes,
                              CartonGroupingService::SIGNATURE_EMPTY for empty boxes)
      - $cartons   : Collection<Carton>
--}}
@php
    use App\Domain\Logistics\Services\CartonGroupingService;

    $count = $cartons->count();
    $firstLabel = $cartons->first()->label;
    $lastLabel = $cartons->last()->label;
    $totalGross = (float) $cartons->sum('gross_weight');
    $totalNet = (float) $cartons->sum('net_weight');
    $totalVolume = (float) $cartons->sum('volume');

    $isMixed = $signature === CartonGroupingService::SIGNATURE_MIXED;
    $isEmpty = $signature === CartonGroupingService::SIGNATURE_EMPTY;

    if ($isMixed) {
        $distinctProducts = $cartons
            ->flatMap(fn ($c) => $c->contents)
            ->pluck('shipment_item_id')
            ->unique()
            ->count();
        $contentSummary = $cartons->flatMap(fn ($c) => $c->contents)->groupBy('shipment_item_id');
    } elseif (! $isEmpty) {
        $firstCartonContents = $cartons->first()->contents;
        $firstContent = $firstCartonContents->first();
        $productName = $firstContent?->shipmentItem?->product_name ?? '—';
        $partLabel = $firstContent?->part_label;
        // Sum, not first row: a box may hold the same product in multiple rows.
        $piecesEach = (int) $firstCartonContents->sum('pieces');
    }

    // "A → B" só quando os labels formam um intervalo contíguo de verdade;
    // grupos esparsos (ex.: Mistas com BOX-001 e BOX-2952) listam os labels
    // ou usam "…" para não parecerem um intervalo de milhares de caixas.
    $labelNumbers = $cartons->map(fn ($c) => preg_match('/(\d+)\s*$/', (string) $c->label, $m) ? (int) $m[1] : null);
    $isContiguous = $count > 1
        && ! $labelNumbers->contains(null)
        && $labelNumbers->unique()->count() === $count
        && ($labelNumbers->max() - $labelNumbers->min() + 1) === $count;

    $rangeLabel = match (true) {
        $count === 1 => $firstLabel,
        $isContiguous => "{$firstLabel} → {$lastLabel}",
        $count <= 4 => $cartons->pluck('label')->implode(', '),
        default => "{$firstLabel} … {$lastLabel}",
    };
    $boxCountLabel = $count === 1 ? '1 box' : number_format($count) . ' boxes';

    $sgKey = md5($signature) . '-' . $cartons->first()->id;
    $cartonIds = $cartons->pluck('id')->values();

    // Lazy rendering: collapsed subgroups render no carton cards at all, and
    // expanded ones paginate — with 3000 boxes the old always-render-and-hide
    // approach shipped megabytes of hidden HTML on every Livewire round-trip.
    $isExpanded = (bool) ($expandedSubgroups[$sgKey] ?? false);
    $visibleCount = (int) ($subgroupLimits[$sgKey] ?? \App\Livewire\Logistics\PackingListBuilder::SUBGROUP_PAGE);
@endphp

<div wire:key="carton-subgroup-{{ md5($signature) }}-{{ $cartons->first()->id }}">
    {{-- Subgroup header --}}
    <div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
                <div class="flex flex-wrap items-center gap-2 text-base">
                    <span class="font-semibold text-gray-900 dark:text-white">
                        {{ $rangeLabel }}
                    </span>
                    <span class="rounded bg-primary-100 px-2 py-0.5 text-sm font-semibold text-primary-800 dark:bg-primary-900 dark:text-primary-200">
                        {{ $boxCountLabel }}
                    </span>
                </div>

                <div class="mt-1 flex flex-wrap gap-x-3 text-sm text-gray-500 dark:text-gray-400">
                    @if ($totalGross > 0)
                        <span>GW {{ number_format($totalGross, 1) }} kg</span>
                    @endif
                    @if ($totalNet > 0)
                        <span>NW {{ number_format($totalNet, 1) }} kg</span>
                    @endif
                    @if ($totalVolume > 0)
                        <span>CBM {{ number_format($totalVolume, 3) }}</span>
                    @endif
                </div>

                <div class="mt-2 space-y-1">
                    @if ($isEmpty)
                        <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <span>•</span>
                            <span class="font-medium">Vazias</span>
                            <span class="text-gray-500">sem conteúdo</span>
                        </div>
                    @elseif ($isMixed)
                        <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <span>•</span>
                            <span class="font-medium">Mistas</span>
                            <span class="text-gray-500">{{ $distinctProducts }} produtos distintos</span>
                        </div>
                        @foreach ($contentSummary as $itemId => $contents)
                            @php
                                $totalPieces = (int) $contents->sum('pieces');
                                $name = $contents->first()->shipmentItem?->product_name ?? '—';
                            @endphp
                            <div class="ml-4 flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                                <span>◦</span>
                                <span>{{ $name }}</span>
                                <span>· {{ number_format($totalPieces) }} pcs</span>
                            </div>
                        @endforeach
                    @else
                        <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <span>•</span>
                            <span class="font-medium">{{ $productName }}</span>
                            @if ($partLabel)
                                <span class="text-xs text-primary-600 dark:text-primary-400">[{{ $partLabel }}]</span>
                            @endif
                            <span class="text-gray-500">{{ number_format($piecesEach) }} pcs / box</span>
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-1.5">
                <button type="button" wire:click="toggleSubgroup('{{ $sgKey }}')"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/30">
                    <span>{{ $isExpanded ? 'Collapse' : 'Expand boxes' }}</span>
                </button>
                <button type="button"
                    wire:click="startBulkEditCartons({{ json_encode($cartonIds) }}, '{{ $sgKey }}')"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/30"
                    title="Editar medidas e peso de todas as {{ $count }} caixas do grupo">
                    <x-heroicon-o-pencil-square class="h-5 w-5" />
                    Editar medidas
                </button>
                <button type="button"
                    wire:click="deleteAllCartons({{ json_encode($cartonIds) }})"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                    title="Delete all {{ $count }} cartons in this subgroup">
                    <x-heroicon-o-trash class="h-5 w-5" />
                    Delete all
                </button>
            </div>
        </div>
    </div>

    {{-- Bulk edit form (dims + weight applied to every box in the subgroup) --}}
    @if ($bulkEditKey === $sgKey)
        <div class="mt-2 space-y-2 rounded-md border border-primary-300 bg-primary-50 p-3 dark:border-primary-700 dark:bg-primary-950">
            <h4 class="text-xs font-semibold uppercase text-primary-900 dark:text-primary-100">
                Editar {{ $boxCountLabel }} ({{ $rangeLabel }})
            </h4>
            <p class="text-xs text-gray-500 dark:text-gray-400">Campo em branco mantém o valor atual de cada caixa.</p>
            <div class="grid grid-cols-2 gap-2">
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">Gross weight (kg)</span>
                    <input type="number" step="0.001" min="0" wire:model="bulkEditForm.gross_weight" placeholder="manter"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">Net weight (kg)</span>
                    <input type="number" step="0.001" min="0" wire:model="bulkEditForm.net_weight" placeholder="auto: 90% gross"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">L (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="bulkEditForm.length" placeholder="manter"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">W (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="bulkEditForm.width" placeholder="manter"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">H (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="bulkEditForm.height" placeholder="manter"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">Preencher L, W e H recalcula o CBM de cada caixa.</p>
            <div class="flex gap-2 pt-1">
                <x-filament::button wire:click="saveBulkEditCartons" size="xs">Salvar grupo</x-filament::button>
                <x-filament::button wire:click="cancelBulkEditCartons" size="xs" color="gray">Cancelar</x-filament::button>
            </div>
        </div>
    @endif

    {{-- Expanded individual cartons (paginated) --}}
    @if ($isExpanded)
        <div class="mt-2 space-y-2">
            @foreach ($cartons->take($visibleCount) as $carton)
                @include('livewire.logistics.partials.carton-card', ['carton' => $carton, 'nested' => true])
            @endforeach

            @if ($count > $visibleCount)
                <button type="button" wire:click="showMoreInSubgroup('{{ $sgKey }}')"
                    class="w-full rounded-md border border-dashed border-gray-300 px-3 py-2 text-sm text-primary-600 hover:bg-primary-50 dark:border-gray-700 dark:hover:bg-primary-900/30">
                    Mostrar mais {{ min(\App\Livewire\Logistics\PackingListBuilder::SUBGROUP_PAGE, $count - $visibleCount) }}
                    ({{ $visibleCount }} de {{ number_format($count) }} exibidas)
                </button>
            @endif
        </div>
    @endif
</div>
