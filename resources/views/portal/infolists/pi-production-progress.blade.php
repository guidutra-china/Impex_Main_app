@php
    $record = $getRecord();
    $schedules = $record->productionSchedules()
        ->with(['entries.proformaInvoiceItem.product'])
        ->get();

    $entries = $schedules->flatMap->entries;

    if ($entries->isEmpty()) {
        $hasData = false;
    } else {
        $hasData = true;

        // Group by PI item, then by date
        $byItem = $entries->groupBy('proforma_invoice_item_id');

        $piItems = $record->items()->with('product')->get()->keyBy('id');

        // Build summary per item
        $itemSummaries = $byItem->map(function ($itemEntries, $piItemId) use ($piItems) {
            $piItem = $piItems[$piItemId] ?? null;
            $totalPlanned = $itemEntries->sum('quantity');
            $totalActual = $itemEntries->sum(fn ($e) => $e->actual_quantity ?? 0);
            $piQty = $piItem?->quantity ?? 0;
            $percent = $piQty > 0 ? round(($totalActual / $piQty) * 100) : 0;

            return (object) [
                'product_name' => $piItem?->product?->name ?? $piItem?->description ?? '—',
                'pi_quantity' => $piQty,
                'total_planned' => $totalPlanned,
                'total_actual' => $totalActual,
                'percent' => min($percent, 100),
                'entries' => $itemEntries->sortBy('production_date'),
            ];
        })->sortBy('product_name');
    }
@endphp

@if($hasData)
    <div class="space-y-6">
        {{-- Summary per item --}}
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium">
                    <tr>
                        <th class="px-4 py-2.5">Product</th>
                        <th class="px-4 py-2.5 text-center">PI Qty</th>
                        <th class="px-4 py-2.5 text-center">Planned</th>
                        <th class="px-4 py-2.5 text-center">Produced</th>
                        <th class="px-4 py-2.5 text-center" style="min-width: 180px;">Progress</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach($itemSummaries as $summary)
                        <tr class="text-gray-900 dark:text-white">
                            <td class="px-4 py-2.5 font-medium">{{ $summary->product_name }}</td>
                            <td class="px-4 py-2.5 text-center">{{ number_format($summary->pi_quantity) }}</td>
                            <td class="px-4 py-2.5 text-center">{{ number_format($summary->total_planned) }}</td>
                            <td class="px-4 py-2.5 text-center font-bold
                                @if($summary->total_actual >= $summary->pi_quantity) text-green-600 dark:text-green-400
                                @elseif($summary->total_actual > 0) text-amber-600 dark:text-amber-400
                                @else text-gray-400 dark:text-gray-500
                                @endif
                            ">
                                {{ number_format($summary->total_actual) }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2.5 overflow-hidden">
                                        <div class="h-full rounded-full transition-all duration-500
                                            @if($summary->percent >= 100) bg-green-500
                                            @elseif($summary->percent >= 50) bg-amber-500
                                            @else bg-blue-500
                                            @endif
                                        " style="width: {{ $summary->percent }}%"></div>
                                    </div>
                                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400 w-10 text-right">{{ $summary->percent }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Detailed timeline per item --}}
        @foreach($itemSummaries as $summary)
            <details class="group rounded-lg border border-gray-200 dark:border-white/10">
                <summary class="flex items-center justify-between cursor-pointer px-4 py-3 bg-gray-50 dark:bg-white/5 rounded-t-lg text-sm font-medium text-gray-700 dark:text-gray-300">
                    <span>{{ $summary->product_name }} — Daily Breakdown</span>
                    <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50/50 dark:bg-white/[0.02] text-gray-500 dark:text-gray-400 text-xs font-medium">
                            <tr>
                                <th class="px-4 py-2">Date</th>
                                <th class="px-4 py-2 text-center">Planned</th>
                                <th class="px-4 py-2 text-center">Actual</th>
                                <th class="px-4 py-2 text-center">Delta</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @foreach($summary->entries as $entry)
                                @php
                                    $delta = $entry->actual_quantity !== null ? $entry->actual_quantity - $entry->quantity : null;
                                @endphp
                                <tr class="text-gray-900 dark:text-white">
                                    <td class="px-4 py-2">{{ $entry->production_date->format('d/m/Y') }}</td>
                                    <td class="px-4 py-2 text-center">{{ number_format($entry->quantity) }}</td>
                                    <td class="px-4 py-2 text-center
                                        @if($entry->actual_quantity === null) text-gray-400
                                        @elseif($entry->actual_quantity >= $entry->quantity) text-green-600 dark:text-green-400
                                        @elseif($entry->actual_quantity > 0) text-amber-600 dark:text-amber-400
                                        @else text-red-600 dark:text-red-400
                                        @endif
                                    ">
                                        {{ $entry->actual_quantity !== null ? number_format($entry->actual_quantity) : '—' }}
                                    </td>
                                    <td class="px-4 py-2 text-center
                                        @if($delta === null) text-gray-400
                                        @elseif($delta >= 0) text-green-600 dark:text-green-400
                                        @else text-red-600 dark:text-red-400
                                        @endif
                                    ">
                                        @if($delta !== null)
                                            {{ $delta >= 0 ? '+' : '' }}{{ number_format($delta) }}
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endforeach
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 italic">No production schedule data available for this proforma invoice.</p>
@endif
