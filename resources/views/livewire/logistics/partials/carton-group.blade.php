{{--
    Renders cartons for a parent (container or pallet).
    When count > threshold, shows a compact summary row instead of N individual cards.
    Individual cards still shown for small counts or manual edits.
--}}
@php
    $threshold = 10;
    $count = $cartons->count();
    $showSummary = $count > $threshold;

    if ($showSummary) {
        $firstLabel = $cartons->first()->label;
        $lastLabel = $cartons->last()->label;
        $totalGross = (float) $cartons->sum('gross_weight');
        $totalNet = (float) $cartons->sum('net_weight');

        // Group by product for summary display
        $contentSummary = $cartons->flatMap(fn ($c) => $c->contents)->groupBy('shipment_item_id');
    }
@endphp

@if ($showSummary)
    {{-- Compact summary for large carton counts --}}
    <div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
        <div class="flex items-start justify-between gap-3">
            <div class="flex-1">
                <div class="flex flex-wrap items-center gap-2 text-base">
                    <span class="font-semibold text-gray-900 dark:text-white">
                        {{ $firstLabel }} → {{ $lastLabel }}
                    </span>
                    <span class="rounded bg-primary-100 px-2 py-0.5 text-sm font-semibold text-primary-800 dark:bg-primary-900 dark:text-primary-200">
                        {{ number_format($count) }} boxes
                    </span>
                </div>

                <div class="mt-1 flex flex-wrap gap-x-3 text-sm text-gray-500 dark:text-gray-400">
                    @if ($totalGross > 0)
                        <span>GW {{ number_format($totalGross, 1) }} kg</span>
                    @endif
                    @if ($totalNet > 0)
                        <span>NW {{ number_format($totalNet, 1) }} kg</span>
                    @endif
                </div>

                {{-- Per-product breakdown --}}
                <div class="mt-2 space-y-1">
                    @foreach ($contentSummary as $itemId => $contents)
                        @php
                            $totalPieces = (int) $contents->sum('pieces');
                            $productName = $contents->first()->shipmentItem?->product_name ?? '—';
                            $partLabel = $contents->first()->part_label;
                            $contentsCount = $contents->count();
                        @endphp
                        <div class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <span>•</span>
                            <span class="font-medium">{{ $productName }}</span>
                            @if ($partLabel)
                                <span class="text-xs text-primary-600 dark:text-primary-400">[{{ $partLabel }}]</span>
                            @endif
                            <span class="text-gray-500">{{ number_format($totalPieces) }} pcs in {{ $contentsCount }} box(es)</span>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Bulk actions for grouped cartons --}}
            <div class="flex items-center gap-1.5">
                <button type="button"
                    wire:click="deleteAllCartons({{ json_encode($cartons->pluck('id')->values()) }})"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                    title="Delete all {{ $count }} cartons">
                    <x-heroicon-o-trash class="h-5 w-5" />
                    Delete all
                </button>
            </div>
        </div>
    </div>
@else
    {{-- Small count: render individual carton cards --}}
    <div class="space-y-2">
        @foreach ($cartons as $carton)
            @include('livewire.logistics.partials.carton-card', ['carton' => $carton, 'nested' => true])
        @endforeach
    </div>
@endif
