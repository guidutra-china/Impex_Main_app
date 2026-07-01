<div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
    <div class="flex items-start justify-between gap-3">
        <div class="flex-1">
            <div class="flex flex-wrap items-center gap-2 text-base">
                <span class="font-semibold text-gray-900 dark:text-white">{{ $carton->label }}</span>
                @if ($carton->packaging_type)
                    <x-filament::badge :color="$carton->packaging_type->getColor()" :icon="$carton->packaging_type->getIcon()" size="xs">
                        {{ $carton->packaging_type->getLabel() }}
                    </x-filament::badge>
                @endif
                @if ($carton->gross_weight)
                    <span class="text-sm text-gray-500">{{ number_format((float) $carton->gross_weight, 1) }} kg</span>
                @endif
                @if ($carton->length && $carton->width && $carton->height)
                    <span class="text-sm text-gray-500">{{ (int) $carton->length }}×{{ (int) $carton->width }}×{{ (int) $carton->height }} cm</span>
                @endif
            </div>

            {{-- Contents --}}
            <div class="mt-1.5 space-y-1 pl-3">
                @forelse ($carton->contents as $content)
                    <div class="flex items-center justify-between text-sm">
                        <div class="flex-1 text-gray-700 dark:text-gray-300">
                            • {{ $content->shipmentItem?->product_name ?? '—' }}
                            @if ($content->part_label)
                                <span class="text-primary-600 dark:text-primary-400">[{{ $content->part_label }}]</span>
                            @endif
                            <span class="text-gray-500">· {{ $content->pieces }} pcs</span>
                        </div>
                        <button type="button" wire:click="deleteContent({{ $content->id }})"
                            class="text-base leading-none text-red-400 hover:text-red-600" title="Remove">×</button>
                    </div>
                @empty
                    <div class="text-sm italic text-gray-400">Empty</div>
                @endforelse
            </div>
        </div>

        <div class="flex items-center gap-1 text-sm">
            <button type="button" wire:click="startAddContent({{ $carton->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-primary-600 hover:bg-primary-100 dark:hover:bg-primary-900/30"
                title="Add product to this box">
                <x-heroicon-o-plus-circle class="h-5 w-5" />
            </button>
            <button type="button" wire:click="startMoveCarton({{ $carton->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800"
                title="Move to container/pallet">
                <x-heroicon-o-arrows-right-left class="h-5 w-5" />
            </button>
            <button type="button" wire:click="startEditCarton({{ $carton->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800"
                title="Edit dimensions/weight">
                <x-heroicon-o-pencil-square class="h-5 w-5" />
            </button>
            <button type="button" wire:click="deleteCarton({{ $carton->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                title="Delete carton (and its contents)">
                <x-heroicon-o-trash class="h-5 w-5" />
            </button>
        </div>
    </div>

    {{-- Add content form --}}
    @if ($addContentCartonId === $carton->id)
        <div class="mt-2 space-y-2 border-t border-gray-200 pt-2 dark:border-gray-700">
            <select wire:model.live="addContentItemId"
                class="block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                <option value="">Select product…</option>
                @foreach ($this->products as $row)
                    @php
                        $rem = $row['progress']?->remaining() ?? $row['item']->quantity;
                        $isSplit = $row['progress']?->isSplit() ?? false;
                    @endphp
                    @if ($rem > 0 && ! $isSplit)
                        <option value="{{ $row['item']->id }}">
                            {{ $row['item']->product_name }} (remaining: {{ $rem }})
                        </option>
                    @endif
                @endforeach
            </select>
            @php
                $selRow = $addContentItemId ? $this->products->firstWhere('item.id', $addContentItemId) : null;
                $selRemaining = $selRow ? ($selRow['progress']?->remaining() ?? $selRow['item']->quantity) : null;
            @endphp
            <input type="number" min="1" wire:model.blur="addContentPieces"
                placeholder="{{ $selRemaining !== null ? 'Restante: '.$selRemaining.' (vazio = tudo)' : 'Peças' }}"
                class="block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
            <div class="flex gap-2">
                <x-filament::button wire:click="confirmAddContent" size="xs">Add</x-filament::button>
                <x-filament::button wire:click="cancelAddContent" size="xs" color="gray">Cancel</x-filament::button>
            </div>
        </div>
    @endif

    {{-- Move carton form --}}
    @if ($moveCartonId === $carton->id)
        <div class="mt-2 space-y-2 border-t border-gray-200 pt-2 dark:border-gray-700">
            <label class="text-xs font-semibold uppercase text-gray-500">Move to…</label>
            <select onchange="@this.call('moveCartonTo', this.value); this.value='';"
                class="block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                <option value="">Choose target…</option>
                <option value="loose">🗑 Loose (no container, no pallet)</option>
                @foreach ($this->containers as $c)
                    <option value="container:{{ $c->id }}">
                        📦 {{ $c->label }}@if ($c->container_number) · {{ $c->container_number }}@endif (direct)
                    </option>
                    @foreach ($c->pallets as $p)
                        <option value="pallet:{{ $p->id }}">&nbsp;&nbsp;🟨 {{ $p->label }} (in {{ $c->label }})</option>
                    @endforeach
                @endforeach
                @foreach ($this->loosePallets as $p)
                    <option value="pallet:{{ $p->id }}">🟨 {{ $p->label }} (loose pallet)</option>
                @endforeach
            </select>
            <div class="flex gap-2">
                <x-filament::button wire:click="cancelMoveCarton" size="xs" color="gray">Cancel</x-filament::button>
            </div>
        </div>
    @endif

    {{-- Edit carton form --}}
    @if ($editCartonId === $carton->id)
        <div class="mt-2 space-y-2 rounded-md border border-primary-300 bg-primary-50 p-3 dark:border-primary-700 dark:bg-primary-950">
            <h4 class="text-xs font-semibold uppercase text-primary-900 dark:text-primary-100">Edit {{ $carton->label }}</h4>
            <label class="block">
                <span class="text-xs text-gray-700 dark:text-gray-300">Tipo de embalagem</span>
                <select wire:model="editCartonForm.packaging_type"
                    class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                    @foreach (\App\Domain\Logistics\Enums\PackagingType::cases() as $type)
                        <option value="{{ $type->value }}">{{ $type->getLabel() }}</option>
                    @endforeach
                </select>
            </label>
            <div class="grid grid-cols-2 gap-2">
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">Gross weight (kg)</span>
                    <input type="number" step="0.001" min="0" wire:model="editCartonForm.gross_weight"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">Net weight (kg)</span>
                    <input type="number" step="0.001" min="0" wire:model="editCartonForm.net_weight" placeholder="auto: 90% gross"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
            </div>
            <div class="grid grid-cols-3 gap-2">
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">L (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="editCartonForm.length"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">W (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="editCartonForm.width"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">H (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="editCartonForm.height"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
            </div>
            <div class="flex gap-2 pt-1">
                <x-filament::button wire:click="saveEditCarton" size="xs">Save</x-filament::button>
                <x-filament::button wire:click="cancelEditCarton" size="xs" color="gray">Cancel</x-filament::button>
            </div>
        </div>
    @endif
</div>
