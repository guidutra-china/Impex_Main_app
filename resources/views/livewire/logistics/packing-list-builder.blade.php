<div class="space-y-4">
    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h2 class="text-lg font-semibold text-gray-950 dark:text-white">
                Packing List · {{ $shipment->reference }}
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ $this->cartonCount }} carton(s) ·
                {{ collect($this->products)->sum(fn ($r) => $r['progress']?->packedComplete ?? 0) }} /
                {{ collect($this->products)->sum(fn ($r) => $r['progress']?->total ?? $r['item']->quantity) }} pieces packed
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-filament::button wire:click="generateFromItems(false)" color="gray" icon="heroicon-o-sparkles">
                Generate from Items
            </x-filament::button>
            <x-filament::button wire:click="createContainer" icon="heroicon-o-cube">
                + Container
            </x-filament::button>
            <x-filament::button wire:click="createPallet" color="gray" icon="heroicon-o-squares-plus">
                + Loose pallet
            </x-filament::button>
            <x-filament::button wire:click="createCarton" color="gray" icon="heroicon-o-plus">
                + Loose box
            </x-filament::button>
        </div>
    </div>

    {{-- Totais gerais do embarque --}}
    @php $totals = $this->shipmentTotals; @endphp
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm shadow-sm dark:border-white/10 dark:bg-gray-900">
        <span class="font-medium text-gray-500 dark:text-gray-400">Totais gerais:</span>
        <span @if ($totals['pallets'] > 0) title="{{ number_format($totals['units'] - $totals['pallets']) }} caixa(s) fora de pallet + {{ number_format($totals['pallets']) }} pallet(s) = {{ number_format($totals['units']) }} volume(s). Caixas em cima de um pallet não contam como volume separado." @endif>
            <span class="text-gray-500 dark:text-gray-400">Volumes:</span>
            <strong class="text-gray-950 dark:text-white">{{ number_format($totals['units']) }}</strong>
            @if ($totals['pallets'] > 0)
                <span class="text-xs text-gray-400 dark:text-gray-500">({{ number_format($totals['boxes']) }} caixas · {{ number_format($totals['pallets']) }} pallet{{ $totals['pallets'] > 1 ? 's' : '' }})</span>
            @endif
        </span>
        <span>
            <span class="text-gray-500 dark:text-gray-400">GW:</span>
            <strong class="text-gray-950 dark:text-white">{{ number_format($totals['gross'], 2) }} kg</strong>
        </span>
        <span>
            <span class="text-gray-500 dark:text-gray-400">NW:</span>
            <strong class="text-gray-950 dark:text-white">{{ number_format($totals['net'], 2) }} kg</strong>
        </span>
        <span>
            <span class="text-gray-500 dark:text-gray-400">CBM:</span>
            <strong class="text-gray-950 dark:text-white">{{ number_format($totals['cbm'], 2) }} m³</strong>
        </span>
    </div>

    {{-- Inline Split form --}}
    @if ($splitItemId)
        @php $splitItem = $this->products->firstWhere('item.id', $splitItemId); @endphp
        <div class="rounded-lg border border-primary-300 bg-primary-50 p-4 dark:border-primary-700 dark:bg-primary-950">
            <div class="mb-3 flex flex-wrap items-start justify-between gap-3">
                <div class="flex-1 min-w-[250px]">
                    <h3 class="text-base font-semibold text-primary-900 dark:text-primary-100">
                        ✂ Split: {{ $splitItem['item']->product_name ?? '' }}
                    </h3>
                    <p class="mt-0.5 text-xs text-primary-800 dark:text-primary-200">
                        Each part will have its own target of {{ $splitItem['item']->quantity ?? 0 }} pcs.
                    </p>
                </div>
                <label class="flex items-center gap-2">
                    <span class="text-xs font-medium text-primary-900 dark:text-primary-100 whitespace-nowrap">Number of parts</span>
                    <input type="number" min="2" max="10" wire:model.live="splitPartsCount"
                        class="block w-20 rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <div class="flex gap-2">
                    <x-filament::button wire:click="confirmSplit" size="sm">Create split</x-filament::button>
                    <x-filament::button wire:click="cancelSplit" size="sm" color="gray">Cancel</x-filament::button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                @foreach (range(0, $splitPartsCount - 1) as $i)
                    <label class="block">
                        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Part {{ $i + 1 }}</span>
                        <input type="text" wire:model="splitPartLabels.{{ $i }}"
                            class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" />
                    </label>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Two-column layout --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {{-- Left: Products --}}
        <div class="space-y-2">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Products</h3>

            @forelse ($this->products as $row)
                @php
                    $item = $row['item'];
                    $progress = $row['progress'];
                    $packed = $progress?->packedComplete ?? 0;
                    $total = $progress?->total ?? $item->quantity;
                    $remaining = $progress?->remaining() ?? $total;
                    $status = $progress?->status?->value ?? 'not_started';
                    $statusIcon = match ($status) { 'complete' => '✓', 'partial' => '⏳', 'not_started' => '❗', default => '•' };
                    $statusColor = match ($status) { 'complete' => 'text-green-600 dark:text-green-400', 'partial' => 'text-amber-600 dark:text-amber-400', 'not_started' => 'text-red-500 dark:text-red-400', default => 'text-gray-500' };
                    $isSplit = $progress?->isSplit() ?? false;
                    $pcsPerCarton = $row['pcs_per_carton'] ?? 0;
                    $cartonWeight = $row['carton_weight'] ?? null;
                @endphp
                <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="{{ $statusColor }} text-lg font-bold">{{ $statusIcon }}</span>
                                @if ($row['model_no'])
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 font-mono text-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                                        {{ $row['model_no'] }}
                                    </span>
                                @endif
                                <span class="text-base font-medium text-gray-900 dark:text-white">{{ $item->product_name }}</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-x-3 text-sm text-gray-500 dark:text-gray-400">
                                <span>{{ $packed }}/{{ $total }} pcs</span>
                                @if (! $isSplit && $remaining > 0)
                                    <span class="text-gray-600 dark:text-gray-300">{{ $remaining }} remaining</span>
                                @endif
                                @if ($pcsPerCarton > 0)
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                        {{ $pcsPerCarton }} pcs/carton
                                    </span>
                                @endif
                                @if ($cartonWeight)
                                    <span class="rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                                        GW {{ number_format((float) $cartonWeight, 1) }} kg
                                    </span>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($isSplit)
                                <x-filament::button wire:click="clearSplit({{ $item->id }})" size="xs" color="gray">
                                    Clear split
                                </x-filament::button>
                            @elseif ($remaining > 0)
                                <x-filament::button wire:click="startPackProduct({{ $item->id }})" size="xs" color="success" icon="heroicon-o-archive-box-arrow-down">
                                    Pack
                                </x-filament::button>
                                <x-filament::button wire:click="startSplit({{ $item->id }})" size="xs" color="gray">
                                    ✂ Split
                                </x-filament::button>
                            @endif
                        </div>
                    </div>

                    {{-- Pack product form (shown when initiated from this product) --}}
                    @if ($fillFromProduct && $fillItemId === $item->id)
                        <div class="mt-3 rounded-md border border-green-300 bg-green-50 p-3 dark:border-green-700 dark:bg-green-950/30">
                            <h4 class="mb-2 flex items-center gap-1.5 text-sm font-semibold text-green-900 dark:text-green-100">
                                <x-heroicon-o-archive-box-arrow-down class="h-4 w-4" />
                                Pack {{ $item->product_name }}
                            </h4>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-4">
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Destination</span>
                                    <input type="search" wire:model.live.debounce.400ms="cartonSearch"
                                        placeholder="Filtrar caixas… (ex.: BOX-154)"
                                        class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" />
                                    <select onchange="@this.call('setFillTarget', this.value)"
                                        class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
                                        <option value="">Select target…</option>
                                        <option value="loose"
                                            @if($fillTargetType === 'loose') selected @endif>
                                            📦 Loose boxes (no container/pallet)
                                        </option>
                                        @foreach ($this->containers as $c)
                                            <option value="container:{{ $c->id }}"
                                                @if($fillTargetType === 'container' && $fillTargetId === $c->id) selected @endif>
                                                📦 {{ $c->label }}@if ($c->container_number) · {{ $c->container_number }}@endif
                                            </option>
                                            @foreach ($c->pallets as $p)
                                                <option value="pallet:{{ $p->id }}"
                                                    @if($fillTargetType === 'pallet' && $fillTargetId === $p->id) selected @endif>
                                                    &nbsp;&nbsp;🟨 {{ $p->label }}
                                                </option>
                                            @endforeach
                                        @endforeach
                                        @foreach ($this->loosePallets as $p)
                                            <option value="pallet:{{ $p->id }}"
                                                @if($fillTargetType === 'pallet' && $fillTargetId === $p->id) selected @endif>
                                                🟨 {{ $p->label }} (loose)
                                            </option>
                                        @endforeach
                                        @if ($this->cartonFillOptions->isNotEmpty())
                                            <optgroup label="{{ trim($cartonSearch) !== '' ? 'Caixas encontradas' : 'Caixas sugeridas (vazias · recentes)' }}">
                                                @foreach ($this->cartonFillOptions as $co)
                                                    <option value="carton:{{ $co['id'] }}"
                                                        @if($fillTargetType === 'carton' && $fillTargetId === $co['id']) selected @endif>
                                                        📥 {{ $co['label'] }}
                                                    </option>
                                                @endforeach
                                            </optgroup>
                                        @endif
                                    </select>
                                    @if ($this->cartonCount > $this->cartonFillOptions->count())
                                        <span class="mt-0.5 block text-xs text-gray-400 dark:text-gray-500">
                                            Mostrando {{ $this->cartonFillOptions->count() }} de {{ number_format($this->cartonCount) }} caixas — use o filtro para buscar outra.
                                        </span>
                                    @endif
                                </label>
                                @php
                                    $packRow = $fillItemId ? $this->products->firstWhere('item.id', $fillItemId) : null;
                                    $packRemaining = $packRow ? ($packRow['progress']?->remaining() ?? $packRow['item']->quantity) : null;
                                    $packStandardPcs = (int) ($packRow['pcs_per_carton'] ?? 0);
                                @endphp
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Pieces</span>
                                    <input type="number" min="1" wire:model.blur="fillPieces"
                                        placeholder="{{ $packRemaining !== null ? 'Restante: '.$packRemaining.' (vazio = tudo)' : 'Peças' }}"
                                        class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" />
                                </label>
                                <label class="block">
                                    <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Pcs por caixa</span>
                                    <input type="number" min="1" wire:model.blur="fillPcsPerCarton"
                                        placeholder="{{ $packStandardPcs > 0 ? 'padrão: '.$packStandardPcs : 'sem padrão (tudo em 1 caixa)' }}"
                                        class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" />
                                </label>
                                <div class="flex items-end gap-2">
                                    <x-filament::button wire:click="confirmFill" size="sm" color="success">Pack</x-filament::button>
                                    <x-filament::button wire:click="cancelFill" size="sm" color="gray">Cancel</x-filament::button>
                                </div>
                            </div>
                            @if ($fillTargetType === 'carton')
                                <div class="mt-2 rounded bg-white p-2 text-sm dark:bg-gray-900">
                                    <span class="font-semibold text-green-700 dark:text-green-400">→ adiciona as peças à caixa selecionada</span>
                                </div>
                            @else
                                @php $preview = $this->fillPreview; @endphp
                                @if ($preview['cartons'] > 0 || $preview['remainder'] > 0)
                                    <div class="mt-2 rounded bg-white p-2 text-sm dark:bg-gray-900">
                                        @if ($preview['cartons'] > 0)
                                            <span class="font-semibold text-green-700 dark:text-green-400">→ {{ number_format($preview['cartons']) }} carton(s)</span>
                                            @if ($preview['per_carton'] > 0)
                                                <span class="text-gray-500">@ {{ $preview['per_carton'] }} pcs/carton</span>
                                            @endif
                                        @endif
                                        @if ($preview['remainder'] > 0)
                                            <span class="text-amber-600 dark:text-amber-400">
                                                · {{ $preview['remainder'] }} pcs remaining (add manually)
                                            </span>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </div>
                    @endif

                    @if ($isSplit && $progress)
                        <div class="mt-3 space-y-2 border-t border-gray-200 pt-3 dark:border-gray-700">
                            @if (collect($progress->parts)->contains(fn ($p) => $p['remaining'] > 0))
                                <input type="search" wire:model.live.debounce.400ms="cartonSearch"
                                    placeholder="Filtrar caixas dos selects abaixo… (ex.: BOX-154)"
                                    class="block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                            @endif
                            @foreach ($progress->parts as $part)
                                @php
                                    $key = $this->placeFormKey($item->id, $part['label']);
                                    $placed = $part['placed']; $target = $part['target']; $partRem = $part['remaining'];
                                    $pct = $target > 0 ? min(100, ($placed / $target) * 100) : 0;
                                    $partComplete = $partRem === 0;
                                @endphp
                                <div class="rounded-md bg-gray-50 p-2 dark:bg-gray-800/50">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">{{ $part['label'] }}</span>
                                            <span class="text-xs text-gray-500">{{ $placed }}/{{ $target }}</span>
                                            @if ($partComplete)<span class="text-xs text-green-600 dark:text-green-400">✓</span>@endif
                                        </div>
                                    </div>
                                    <div class="mt-1 h-1 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full {{ $partComplete ? 'bg-green-500' : 'bg-primary-500' }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    @if (! $partComplete)
                                        <div class="mt-2 flex items-center gap-1">
                                            <select wire:model="placeForm.{{ $key }}.cartonId"
                                                class="flex-1 rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                                                <option value="">Box…</option>
                                                @foreach ($this->cartonFillOptions as $co)
                                                    <option value="{{ $co['id'] }}">{{ $co['label'] }}</option>
                                                @endforeach
                                            </select>
                                            <input type="number" min="1" max="{{ $partRem }}" placeholder="pcs"
                                                wire:model="placeForm.{{ $key }}.pieces"
                                                class="w-16 rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                                            <button type="button"
                                                wire:click="placePartPieces({{ $item->id }}, '{{ addslashes($part['label']) }}')"
                                                class="rounded bg-primary-600 px-2 py-1 text-xs font-medium text-white hover:bg-primary-700">
                                                Add
                                            </button>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700">
                    No items on this shipment yet.
                </div>
            @endforelse
        </div>

        {{-- Right: Cargo tree (Containers > Pallets > Cartons + loose levels) --}}
        <div class="space-y-3">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Cargo</h3>

            {{-- Containers --}}
            @foreach ($this->containers as $container)
                @include('livewire.logistics.partials.container-card', ['container' => $container])
            @endforeach

            {{-- Loose pallets (no container) --}}
            @if ($this->loosePallets->isNotEmpty())
                <div class="space-y-2">
                    @foreach ($this->loosePallets as $pallet)
                        @include('livewire.logistics.partials.pallet-card', ['pallet' => $pallet, 'nested' => false])
                    @endforeach
                </div>
            @endif

            {{-- Loose cartons (no container, no pallet) --}}
            @if ($this->looseCartons->isNotEmpty())
                @include('livewire.logistics.partials.carton-group', ['cartons' => $this->looseCartons])
            @endif

            @if ($this->containers->isEmpty() && $this->loosePallets->isEmpty() && $this->looseCartons->isEmpty())
                <div class="rounded-lg border border-dashed border-gray-300 p-6 text-center text-sm text-gray-400 dark:border-gray-700">
                    No cartons, pallets, or containers yet. Click "+ Container", "+ Loose pallet", "+ Loose box", or "Generate from Items".
                </div>
            @endif
        </div>
    </div>
</div>
