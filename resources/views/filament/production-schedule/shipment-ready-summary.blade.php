{{--
    Blade partial: Per-item shipment-ready quantity breakdown.
    Used by ProductionScheduleInfolist Production Summary section.

    $getRecord() returns the ProductionSchedule model.
--}}
@php
    $schedule = $getRecord();
    $byItem = $schedule->getShipmentReadyQuantityByItem();
    $schedule->load('entries.proformaInvoiceItem');
    // Build a lookup: pi_item_id => description/name
    $itemNames = $schedule->entries
        ->pluck('proformaInvoiceItem')
        ->filter()
        ->unique('id')
        ->mapWithKeys(fn ($item) => [
            $item->id => $item->product?->name ?? $item->description ?? "Item #{$item->id}",
        ]);
@endphp

@if ($byItem->isEmpty())
    <span class="text-sm text-gray-400 dark:text-gray-500">—</span>
@else
    <ul class="space-y-1">
        @foreach ($byItem as $itemId => $qty)
            <li class="flex items-center justify-between text-sm">
                <span class="text-gray-700 dark:text-gray-300">
                    {{ $itemNames->get($itemId, "Item #{$itemId}") }}
                </span>
                <span class="font-semibold tabular-nums text-gray-900 dark:text-gray-100 ml-4">
                    {{ number_format($qty) }}
                </span>
            </li>
        @endforeach
    </ul>
@endif
