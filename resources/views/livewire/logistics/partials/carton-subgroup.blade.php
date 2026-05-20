{{--
    Renders one subgroup of cartons sharing the same content signature.
    Collapsed by default, with its own expand toggle and "Delete all" scoped
    to the subgroup.

    Props:
      - $signature : string  (CartonGroupingService::SIGNATURE_MIXED for mixed boxes)
      - $cartons   : Collection<Carton>
--}}
@php
    use App\Domain\Logistics\Services\CartonGroupingService;

    $count = $cartons->count();
    $firstLabel = $cartons->first()->label;
    $lastLabel = $cartons->last()->label;
    $totalGross = (float) $cartons->sum('gross_weight');
    $totalNet = (float) $cartons->sum('net_weight');

    $isMixed = $signature === CartonGroupingService::SIGNATURE_MIXED;

    if ($isMixed) {
        $distinctProducts = $cartons
            ->flatMap(fn ($c) => $c->contents)
            ->pluck('shipment_item_id')
            ->unique()
            ->count();
        $contentSummary = $cartons->flatMap(fn ($c) => $c->contents)->groupBy('shipment_item_id');
    } else {
        $firstContent = $cartons->first()->contents->first();
        $productName = $firstContent?->shipmentItem?->product_name ?? '—';
        $partLabel = $firstContent?->part_label;
        $piecesEach = (int) ($firstContent?->pieces ?? 0);
    }

    $rangeLabel = $count > 1 ? "{$firstLabel} → {$lastLabel}" : $firstLabel;
    $boxCountLabel = $count === 1 ? '1 box' : number_format($count) . ' boxes';
@endphp

<div wire:key="carton-subgroup-{{ md5($signature) }}-{{ $cartons->first()->id }}" x-data="{ expanded: false }">
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
                </div>

                <div class="mt-2 space-y-1">
                    @if ($isMixed)
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
                <button type="button" x-on:click="expanded = ! expanded"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-900/30">
                    <span x-show="! expanded">Expand boxes</span>
                    <span x-show="expanded" style="display: none;">Collapse</span>
                </button>
                <button type="button"
                    wire:click="deleteAllCartons({{ json_encode($cartons->pluck('id')->values()) }})"
                    class="inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
                    title="Delete all {{ $count }} cartons in this subgroup">
                    <x-heroicon-o-trash class="h-5 w-5" />
                    Delete all
                </button>
            </div>
        </div>
    </div>

    {{-- Expanded individual cartons --}}
    <div x-show="expanded" style="display: none;" class="mt-2 space-y-2">
        @foreach ($cartons as $carton)
            @include('livewire.logistics.partials.carton-card', ['carton' => $carton, 'nested' => true])
        @endforeach
    </div>
</div>
