<div wire:key="actuals-grid-{{ $schedule->id }}">
    @if(!$isVisible)
        <p class="text-sm text-gray-400 italic p-4">
            Actuals entry is available once the schedule is approved.
        </p>
    @else
        <div class="space-y-4">
            @php
                $totalPlanned = collect($planned)->map(fn($d) => array_sum($d))->sum();
                $totalActual  = collect($actuals)->map(fn($d) => array_sum(array_filter($d ?? [], fn($v) => $v !== null)))->sum();
                $pct = $totalPlanned > 0 ? min(100, round(($totalActual / $totalPlanned) * 100)) : 0;
            @endphp
            <div class="flex items-center gap-3 px-1">
                <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2">
                    <div class="h-2 rounded-full {{ $pct >= 100 ? 'bg-green-500' : ($pct > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                         style="width: {{ $pct }}%"></div>
                </div>
                <span class="text-sm font-semibold {{ $pct >= 100 ? 'text-green-600' : 'text-gray-600 dark:text-gray-300' }}">
                    {{ $pct }}% ({{ number_format($totalActual) }} / {{ number_format($totalPlanned) }})
                </span>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-white/5 text-xs font-medium text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-2.5 text-left min-w-[150px]">Product</th>
                            <th class="px-3 py-2.5 text-center">PI Qty</th>
                            @foreach($dates as $date)
                                @php $isToday = $date === $today; @endphp
                                <th class="px-3 py-2.5 text-center min-w-[100px] {{ $isToday ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                                    <div class="{{ $isToday ? 'text-blue-600 dark:text-blue-400 font-bold' : '' }}">
                                        {{ \Carbon\Carbon::parse($date)->format('d/m') }}
                                    </div>
                                </th>
                            @endforeach
                            <th class="px-3 py-2.5 text-center min-w-[100px]">Progress</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach($items as $item)
                            @php
                                $itemKey = 'item-' . $item['id'];
                                $itemTotalPlanned = array_sum($planned[$itemKey] ?? []);
                                $itemTotalActual = array_sum(array_filter($actuals[$itemKey] ?? [], fn($v) => $v !== null));
                                $itemPct = $itemTotalPlanned > 0 ? min(100, (int) round(($itemTotalActual / $itemTotalPlanned) * 100)) : 0;
                            @endphp
                            <tr class="text-gray-900 dark:text-white">
                                <td class="px-4 py-2.5">
                                    <div class="font-medium">{{ $item['name'] }}</div>
                                    @if($item['sku'])
                                        <div class="text-xs text-gray-400">{{ $item['sku'] }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-2.5 text-center text-gray-500">{{ number_format($item['pi_quantity']) }}</td>
                                @foreach($dates as $date)
                                    @php
                                        $plan   = $planned[$itemKey][$date] ?? null;
                                        $actual = $actuals[$itemKey][$date] ?? null;
                                        $isToday = $date === $today;
                                        $isPast  = $date < $today;
                                        $bgClass = $isToday
                                            ? 'bg-blue-50 dark:bg-blue-900/20'
                                            : ($isPast && $actual !== null
                                                ? ($actual >= ($plan ?? 0) ? 'bg-green-50 dark:bg-green-900/20' : 'bg-amber-50 dark:bg-amber-900/20')
                                                : '');
                                    @endphp
                                    <td class="px-2 py-1.5 text-center {{ $bgClass }}">
                                        @if($date <= $today && $plan)
                                            <div class="flex flex-col items-center gap-0.5">
                                                <input type="number" min="0" value="{{ $actual ?? '' }}" placeholder="—"
                                                    wire:change="updateActual({{ $item['id'] }}, '{{ $date }}', $event.target.value)"
                                                    class="w-16 text-center text-sm border border-gray-300 dark:border-white/20 rounded px-1 py-0.5 bg-white dark:bg-white/5 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                                                <span class="text-xs text-gray-400">/{{ number_format($plan) }}</span>
                                            </div>
                                        @elseif($plan)
                                            <span class="text-gray-400">—/{{ number_format($plan) }}</span>
                                        @else
                                            <span class="text-gray-300">—</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="px-3 py-2.5">
                                    <div class="flex items-center gap-1.5">
                                        <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2 min-w-[50px]">
                                            <div class="h-2 rounded-full {{ $itemPct >= 100 ? 'bg-green-500' : ($itemPct > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                                 style="width: {{ $itemPct }}%"></div>
                                        </div>
                                        <span class="text-xs font-medium text-gray-500 w-8 text-right">{{ $itemPct }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex justify-end">
                <x-filament::button wire:click="saveActuals" wire:loading.attr="disabled" icon="heroicon-o-check" color="primary">
                    Save Actuals
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
