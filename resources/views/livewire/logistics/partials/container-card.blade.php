@php
    $cartonsInContainer = $container->cartons;
    $totalWeight = (float) $cartonsInContainer->sum('gross_weight');
    $totalVolume = (float) $cartonsInContainer->sum('volume');
    $totalCartons = $cartonsInContainer->count();
@endphp

<div class="rounded-lg border-2 border-blue-300 bg-blue-50/30 p-4 dark:border-blue-800 dark:bg-blue-950/20">
    {{-- Header --}}
    <div class="mb-3 flex items-center justify-between gap-3">
        <div class="flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-2xl">📦</span>
                <span class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ $container->label }}
                </span>
                @if ($container->container_number)
                    <span class="text-sm font-mono text-gray-600 dark:text-gray-300">· {{ $container->container_number }}</span>
                @endif
                @if ($container->type)
                    <span class="rounded bg-blue-200 px-2 py-0.5 text-sm font-semibold text-blue-900 dark:bg-blue-800 dark:text-blue-100">
                        {{ $container->type }}
                    </span>
                @endif
            </div>
            <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ $totalCartons }} box(es)
                @if ($totalWeight > 0) · {{ number_format($totalWeight, 1) }} kg @endif
                @if ($totalVolume > 0) · {{ number_format($totalVolume, 3) }} CBM @endif
                @if ($container->seal_number) · seal {{ $container->seal_number }} @endif
            </div>
        </div>
        <div class="flex items-center gap-1.5 text-sm">
            <x-filament::button
                wire:click="startFillContainer({{ $container->id }})"
                size="sm"
                color="success"
                icon="heroicon-o-bolt"
                tooltip="Bulk-fill this container with a product"
            >
                Fill
            </x-filament::button>
            <button type="button" wire:click="createPallet({{ $container->id }})"
                class="rounded-md px-3 py-1.5 text-sm font-medium text-primary-600 hover:bg-primary-100 dark:hover:bg-primary-900/30"
                title="Add pallet to this container">+ Pallet</button>
            <button type="button" wire:click="createCarton({{ $container->id }}, null)"
                class="rounded-md px-3 py-1.5 text-sm font-medium text-primary-600 hover:bg-primary-100 dark:hover:bg-primary-900/30"
                title="Add box directly to this container">+ Box</button>
            <button type="button" wire:click="startEditContainer({{ $container->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-800"
                title="Edit container">
                <x-heroicon-o-pencil-square class="h-5 w-5" />
            </button>
            <button type="button" wire:click="deleteContainer({{ $container->id }})"
                class="inline-flex items-center rounded-md px-2 py-1.5 text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                title="Delete container (contents become loose)">
                <x-heroicon-o-trash class="h-5 w-5" />
            </button>
        </div>
    </div>

    {{-- Bulk fill form --}}
    @if ($fillTargetType === 'container' && $fillTargetId === $container->id)
        @include('livewire.logistics.partials.fill-form', ['targetLabel' => $container->label])
    @endif

    {{-- Edit form --}}
    @if ($editContainerId === $container->id)
        <div class="mb-3 space-y-2 rounded-md border border-primary-300 bg-primary-50 p-3 dark:border-primary-700 dark:bg-primary-950">
            <h4 class="text-xs font-semibold uppercase text-primary-900 dark:text-primary-100">Edit {{ $container->label }}</h4>
            <div class="grid grid-cols-2 gap-2">
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">Container number</span>
                    <input type="text" wire:model="editContainerForm.container_number" placeholder="CCLU7730065"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
                </label>
                <label class="block">
                    <span class="text-xs text-gray-700 dark:text-gray-300">Type</span>
                    <select wire:model="editContainerForm.type"
                        class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900">
                        <option value="">—</option>
                        <option value="20GP">20GP</option>
                        <option value="40GP">40GP</option>
                        <option value="40HQ">40HQ</option>
                        <option value="45HQ">45HQ</option>
                        <option value="LCL">LCL</option>
                        <option value="AIR">AIR</option>
                    </select>
                </label>
            </div>
            <label class="block">
                <span class="text-xs text-gray-700 dark:text-gray-300">Seal number</span>
                <input type="text" wire:model="editContainerForm.seal_number"
                    class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900" />
            </label>
            <label class="block">
                <span class="text-xs text-gray-700 dark:text-gray-300">Notes</span>
                <textarea wire:model="editContainerForm.notes" rows="2"
                    class="mt-0.5 block w-full rounded border-gray-300 text-xs dark:border-gray-700 dark:bg-gray-900"></textarea>
            </label>
            <div class="flex gap-2 pt-1">
                <x-filament::button wire:click="saveEditContainer" size="xs">Save</x-filament::button>
                <x-filament::button wire:click="cancelEditContainer" size="xs" color="gray">Cancel</x-filament::button>
            </div>
        </div>
    @endif

    {{-- Nested pallets --}}
    @foreach ($container->pallets as $pallet)
        <div class="ml-4 mt-2">
            @include('livewire.logistics.partials.pallet-card', ['pallet' => $pallet, 'nested' => true])
        </div>
    @endforeach

    {{-- Direct cartons (no pallet inside this container) --}}
    @php $directCartons = $container->directCartons; @endphp
    @if ($directCartons->count() > 0)
        <div class="ml-4 mt-2">
            @include('livewire.logistics.partials.carton-group', ['cartons' => $directCartons])
        </div>
    @endif

    @if ($container->pallets->isEmpty() && $directCartons->isEmpty())
        <div class="ml-4 mt-2 text-sm italic text-gray-400">Empty — add a pallet or a box.</div>
    @endif
</div>
