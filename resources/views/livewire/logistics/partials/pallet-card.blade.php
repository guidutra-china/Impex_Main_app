@php
    $cartonsOnPallet = $pallet->cartons;
    $totalWeight = (float) $cartonsOnPallet->sum('gross_weight');
@endphp

<div class="rounded-md border border-amber-300 bg-amber-50/40 p-3 dark:border-amber-800 dark:bg-amber-950/20">
    <div class="mb-2 flex items-center justify-between gap-3">
        <div class="flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-xl">🟨</span>
                <span class="text-base font-semibold text-gray-900 dark:text-white">{{ $pallet->label }}</span>
                @if ($pallet->length && $pallet->width)
                    <span class="text-sm text-gray-500">{{ (int) $pallet->length }}×{{ (int) $pallet->width }}@if ($pallet->height)×{{ (int) $pallet->height }}@endif cm</span>
                @endif
            </div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ $cartonsOnPallet->count() }} box(es)
                @if ($totalWeight > 0) · {{ number_format($totalWeight, 1) }} kg @endif
            </div>
        </div>
        <div class="flex items-center gap-1.5 text-sm">
            <x-filament::button
                wire:click="startFillPallet({{ $pallet->id }})"
                size="sm"
                color="success"
                icon="heroicon-o-bolt"
                tooltip="Bulk-fill this pallet with a product"
            >
                Fill
            </x-filament::button>
            <button type="button" wire:click="createCarton({{ $pallet->shipment_container_id ? $pallet->shipment_container_id : 'null' }}, {{ $pallet->id }})"
                class="rounded-md px-3 py-1.5 text-sm font-medium text-primary-600 hover:bg-primary-100 dark:hover:bg-primary-900/30"
                title="Add box to this pallet">+ Box</button>
            <button type="button" wire:click="startMovePallet({{ $pallet->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800"
                title="Move pallet to container">
                <x-heroicon-o-arrows-right-left class="h-5 w-5" />
            </button>
            <button type="button" wire:click="startEditPallet({{ $pallet->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800"
                title="Edit pallet">
                <x-heroicon-o-pencil-square class="h-5 w-5" />
            </button>
            <button type="button" wire:click="deletePallet({{ $pallet->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                title="Delete pallet (cartons stay in container)">
                <x-heroicon-o-trash class="h-5 w-5" />
            </button>
        </div>
    </div>

    {{-- Bulk fill form --}}
    @if ($fillTargetType === 'pallet' && $fillTargetId === $pallet->id)
        @include('livewire.logistics.partials.fill-form', ['targetLabel' => $pallet->label])
    @endif

    {{-- Move pallet form --}}
    @if ($movePalletId === $pallet->id)
        <div class="mb-2 space-y-2 border-t border-amber-200 pt-2 dark:border-amber-800">
            <label class="text-xs font-semibold uppercase text-gray-500">Move {{ $pallet->label }} to…</label>
            <select onchange="@this.call('movePalletTo', this.value); this.value='';"
                class="block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                <option value="">Choose target…</option>
                <option value="loose">🗑 Loose (no container)</option>
                @foreach ($this->containers as $c)
                    @if ($c->id !== $pallet->shipment_container_id)
                        <option value="container:{{ $c->id }}">
                            📦 {{ $c->label }}@if ($c->container_number) · {{ $c->container_number }}@endif
                        </option>
                    @endif
                @endforeach
            </select>
            <div class="flex gap-2">
                <x-filament::button wire:click="cancelMovePallet" size="xs" color="gray">Cancel</x-filament::button>
            </div>
        </div>
    @endif

    {{-- Edit pallet form --}}
    @if ($editPalletId === $pallet->id)
        <div class="mb-2 space-y-2 rounded-md border border-primary-300 bg-primary-50 p-3 dark:border-primary-700 dark:bg-primary-950">
            <h4 class="text-xs font-semibold uppercase text-primary-900 dark:text-primary-100">Edit {{ $pallet->label }}</h4>
            <div class="grid grid-cols-3 gap-2">
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">L (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="editPalletForm.length"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">W (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="editPalletForm.width"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">H (cm)</span>
                    <input type="number" step="0.01" min="0" wire:model="editPalletForm.height"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
            </div>
            <label class="block">
                <span class="text-xs text-gray-700 dark:text-gray-300">Move to container</span>
                <select wire:model="editPalletForm.shipment_container_id"
                    class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Loose (no container)</option>
                    @foreach ($this->containers as $c)
                        <option value="{{ $c->id }}">{{ $c->label }}@if ($c->container_number) · {{ $c->container_number }}@endif</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="text-xs text-gray-700 dark:text-gray-300">Notes</span>
                <textarea wire:model="editPalletForm.notes" rows="2"
                    class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
            </label>
            <div class="flex gap-2 pt-1">
                <x-filament::button wire:click="saveEditPallet" size="xs">Save</x-filament::button>
                <x-filament::button wire:click="cancelEditPallet" size="xs" color="gray">Cancel</x-filament::button>
            </div>
        </div>
    @endif

    {{-- Cartons on the pallet --}}
    @if ($cartonsOnPallet->isNotEmpty())
        <div class="ml-3 mt-1">
            @include('livewire.logistics.partials.carton-group', ['cartons' => $cartonsOnPallet])
        </div>
    @else
        <div class="ml-3 mt-1 text-sm italic text-gray-400">Empty pallet — add a box.</div>
    @endif
</div>
