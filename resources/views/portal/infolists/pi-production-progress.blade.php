@php
    $record = $getRecord();
    $schedules = $record->productionSchedules()
        ->with(['entries.proformaInvoiceItem.product', 'components.deliveries'])
        ->get();

    $entries = $schedules->flatMap->entries;

    if ($entries->isEmpty() && $schedules->flatMap->components->isEmpty()) {
        $hasData = false;
    } else {
        $hasData = true;

        // --- PRODUCTION GRID ---
        $piItems = $record->items()->with('product')->get()->keyBy('id');
        $byItem = $entries->groupBy('proforma_invoice_item_id');

        // Collect all production dates
        $productionDates = $entries->pluck('production_date')
            ->map->format('Y-m-d')
            ->unique()
            ->sort()
            ->values();

        // Build production rows
        $productionRows = $byItem->map(function ($itemEntries, $piItemId) use ($piItems, $productionDates) {
            $piItem = $piItems[$piItemId] ?? null;
            $piQty = $piItem?->quantity ?? 0;
            $totalActual = $itemEntries->sum(fn ($e) => $e->actual_quantity ?? 0);
            $percent = $piQty > 0 ? min(100, (int) round(($totalActual / $piQty) * 100)) : 0;

            // Map entries by date
            $byDate = [];
            foreach ($itemEntries as $entry) {
                $byDate[$entry->production_date->format('Y-m-d')] = $entry;
            }

            return (object) [
                'product_name'    => $piItem?->product?->name ?? $piItem?->description ?? '—',
                'pi_quantity'     => $piQty,
                'total_actual'    => $totalActual,
                'percent'         => $percent,
                'entries_by_date' => $byDate,
            ];
        })->sortBy('product_name');

        // --- COMPONENTS GRID ---
        $allComponents = $schedules->flatMap->components;
        $componentDates = $allComponents
            ->flatMap(fn ($c) => $c->deliveries->pluck('expected_date'))
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values();

        $componentRows = $allComponents->map(function ($comp) use ($piItems) {
            $piItem = $piItems[$comp->proforma_invoice_item_id] ?? null;
            $totalReceived = $comp->totalReceived();
            $percent = $comp->quantity_required > 0
                ? min(100, (int) round(($totalReceived / $comp->quantity_required) * 100))
                : 0;

            $deliveriesByDate = [];
            foreach ($comp->deliveries as $d) {
                $deliveriesByDate[$d->expected_date->format('Y-m-d')] = $d;
            }

            return (object) [
                'name'               => $comp->component_name,
                'product_name'       => $piItem?->product?->name ?? '—',
                'quantity_required'  => $comp->quantity_required,
                'total_received'     => $totalReceived,
                'percent'            => $percent,
                'deliveries_by_date' => $deliveriesByDate,
            ];
        })->sortBy('name');
    }
@endphp

@if($hasData)
    <div class="space-y-6">
        {{-- PRODUCTION PROGRESS GRID --}}
        @if($productionDates->count() > 0)
            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm text-left">
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                        <tr>
                            <th class="px-4 py-2.5 min-w-[140px]">Product</th>
                            <th class="px-3 py-2.5 text-center">PI Qty</th>
                            @foreach($productionDates as $date)
                                <th class="px-3 py-2.5 text-center min-w-[80px]">
                                    {{ \Carbon\Carbon::parse($date)->format('d/m') }}
                                </th>
                            @endforeach
                            <th class="px-3 py-2.5 text-center min-w-[100px]">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach($productionRows as $row)
                            <tr class="text-gray-900 dark:text-white">
                                <td class="px-4 py-2.5 font-medium">{{ $row->product_name }}</td>
                                <td class="px-3 py-2.5 text-center text-gray-500">{{ number_format($row->pi_quantity) }}</td>
                                @foreach($productionDates as $date)
                                    @php
                                        $entry = $row->entries_by_date[$date] ?? null;
                                        $planned = $entry?->quantity ?? 0;
                                        $actual = $entry?->actual_quantity;
                                    @endphp
                                    <td class="px-3 py-2.5 text-center text-xs">
                                        @if($entry)
                                            <span class="{{ $actual !== null ? ($actual >= $planned ? 'text-green-600 dark:text-green-400 font-bold' : 'text-amber-600 dark:text-amber-400 font-bold') : 'text-gray-400' }}">{{ $actual !== null ? number_format($actual) : '—' }}</span><span class="text-gray-400">/{{ number_format($planned) }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-1.5">
                                        <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2 min-w-[50px]">
                                            <div class="h-2 rounded-full {{ $row->percent >= 100 ? 'bg-green-500' : ($row->percent > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                                 style="width: {{ $row->percent }}%"></div>
                                        </div>
                                        <span class="text-xs font-medium text-gray-500 w-8 text-right">{{ $row->percent }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- COMPONENTS GRID --}}
        @if($componentRows->count() > 0)
            <div>
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2 flex items-center gap-1.5">
                    <x-heroicon-o-truck class="w-4 h-4"/>
                    Components
                </h4>
                <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                            <tr>
                                <th class="px-4 py-2.5 min-w-[140px]">Component</th>
                                <th class="px-3 py-2.5 text-center">Needed</th>
                                @foreach($componentDates as $date)
                                    <th class="px-3 py-2.5 text-center min-w-[70px]">
                                        {{ \Carbon\Carbon::parse($date)->format('d/m') }}
                                    </th>
                                @endforeach
                                <th class="px-3 py-2.5 text-center">Received</th>
                                <th class="px-3 py-2.5 text-center min-w-[100px]">Progress</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                            @foreach($componentRows as $comp)
                                <tr class="text-gray-900 dark:text-white">
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium">{{ $comp->name }}</div>
                                        <div class="text-xs text-gray-400">{{ $comp->product_name }}</div>
                                    </td>
                                    <td class="px-3 py-2.5 text-center font-semibold">{{ number_format($comp->quantity_required) }}</td>
                                    @foreach($componentDates as $date)
                                        @php $delivery = $comp->deliveries_by_date[$date] ?? null; @endphp
                                        <td class="px-3 py-2.5 text-center text-xs {{ $delivery?->isReceived() ? 'bg-green-50 dark:bg-green-900/20' : '' }}">
                                            @if($delivery)
                                                @if($delivery->isReceived())
                                                    <span class="font-bold text-green-600 dark:text-green-400">{{ number_format($delivery->received_qty) }} ✓</span>
                                                @else
                                                    <span class="text-gray-600 dark:text-gray-300">{{ number_format($delivery->expected_qty) }}</span>
                                                @endif
                                            @else
                                                <span class="text-gray-300">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="px-3 py-2.5 text-center font-bold {{ $comp->total_received >= $comp->quantity_required ? 'text-green-600 dark:text-green-400' : '' }}">
                                        {{ number_format($comp->total_received) }}/{{ number_format($comp->quantity_required) }}
                                    </td>
                                    <td class="px-3 py-2.5">
                                        <div class="flex items-center gap-1.5">
                                            <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2 min-w-[50px]">
                                                <div class="h-2 rounded-full {{ $comp->percent >= 100 ? 'bg-green-500' : ($comp->percent > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                                     style="width: {{ $comp->percent }}%"></div>
                                            </div>
                                            <span class="text-xs font-medium text-gray-500 w-8 text-right">{{ $comp->percent }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Approval widgets per schedule --}}
        @foreach($schedules->where('status', 'pending_approval') as $pendingSchedule)
            <div class="mt-4">
                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                    Schedule {{ $pendingSchedule->reference }} — Pending Your Approval
                </h4>
                <livewire:portal.schedule-approval-widget
                    :schedule="$pendingSchedule"
                    :key="'approval-widget-' . $pendingSchedule->id"
                />
            </div>
        @endforeach
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 italic">No production schedule data available for this proforma invoice.</p>
@endif
