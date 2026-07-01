{{-- Shared bulk-fill form, rendered inside container-card or pallet-card when active --}}
<div class="mb-3 space-y-2 rounded-md border border-green-300 bg-green-50 p-4 dark:border-green-700 dark:bg-green-950/30">
    <h4 class="flex items-center gap-1.5 text-sm font-semibold uppercase text-green-900 dark:text-green-100">
        <x-heroicon-o-bolt class="h-5 w-5" />
        Fill {{ $targetLabel }}
    </h4>
    <p class="text-xs text-green-800 dark:text-green-200">
        Pick a product and quantity — cartons will be auto-sized from product packaging and created in bulk.
    </p>

    <label class="block">
        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Product</span>
        <select wire:model.live="fillItemId"
            class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900">
            <option value="">Select product…</option>
            @foreach ($this->products as $row)
                @php
                    $rem = $row['progress']?->remaining() ?? $row['item']->quantity;
                    $isSplit = $row['progress']?->isSplit() ?? false;
                @endphp
                @if ($rem > 0 && ! $isSplit)
                    <option value="{{ $row['item']->id }}">
                        {{ $row['item']->product_name }} ({{ $rem }} pcs remaining)
                    </option>
                @endif
            @endforeach
        </select>
    </label>

    <label class="block">
        <span class="text-xs font-medium text-gray-700 dark:text-gray-300">Quantity (pieces)</span>
        @php
            $ffRow = $fillItemId ? $this->products->firstWhere('item.id', $fillItemId) : null;
            $ffRemaining = $ffRow ? ($ffRow['progress']?->remaining() ?? $ffRow['item']->quantity) : null;
        @endphp
        <input type="number" min="1" wire:model.blur="fillPieces"
            placeholder="{{ $ffRemaining !== null ? 'Restante: '.$ffRemaining.' (vazio = tudo)' : 'Peças' }}"
            class="mt-0.5 block w-full rounded-lg border-gray-300 text-sm dark:border-gray-700 dark:bg-gray-900" />
    </label>

    @php $preview = $this->fillPreview; @endphp
    @if ($preview['cartons'] > 0 || $preview['remainder'] > 0)
        <div class="rounded bg-white p-2 text-sm dark:bg-gray-900">
            @if ($preview['cartons'] > 0)
                <span class="font-semibold text-green-700 dark:text-green-400">→ {{ number_format($preview['cartons']) }} carton(s)</span>
                @if ($preview['per_carton'] > 0)
                    <span class="text-gray-500">@ {{ $preview['per_carton'] }} pcs/carton</span>
                @endif
            @endif
            @if ($preview['remainder'] > 0)
                <span class="text-amber-600 dark:text-amber-400">
                    · {{ $preview['remainder'] }} pcs remaining (add manually to a mixed box)
                </span>
            @endif
        </div>
    @endif

    <div class="flex gap-2 pt-1">
        <x-filament::button wire:click="confirmFill" size="sm" color="success">Pack</x-filament::button>
        <x-filament::button wire:click="cancelFill" size="sm" color="gray">Cancel</x-filament::button>
    </div>
</div>
