<x-filament-widgets::widget>
    <x-filament::section
        heading="Upcoming Payments"
        icon="heroicon-o-calendar-days"
    >
        @if (! $hasAny)
            <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 dark:border-gray-700 dark:bg-white/5">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-success-400 dark:text-success-600" />
                <span class="text-sm font-medium text-success-600 dark:text-success-400">No upcoming payments due</span>
            </div>
        @else
            {{-- Summary Cards --}}
            <div class="grid grid-cols-3 gap-3 mb-6">
                {{-- Overdue --}}
                <div @class([
                    'rounded-xl border p-4',
                    'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/5' => $overdueCount > 0,
                    'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $overdueCount === 0,
                ])>
                    <div class="flex items-center gap-3">
                        <div @class([
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                            'bg-danger-200 dark:bg-danger-500/20' => $overdueCount > 0,
                            'bg-gray-200 dark:bg-white/10' => $overdueCount === 0,
                        ])>
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" @class([
                                'h-5 w-5',
                                'text-danger-600 dark:text-danger-400' => $overdueCount > 0,
                                'text-gray-400 dark:text-gray-500' => $overdueCount === 0,
                            ]) />
                        </div>
                        <div class="min-w-0">
                            <p @class([
                                'text-[0.65rem] font-semibold uppercase tracking-wide',
                                'text-danger-600 dark:text-danger-400' => $overdueCount > 0,
                                'text-gray-400 dark:text-gray-500' => $overdueCount === 0,
                            ])>Overdue</p>
                            <p @class([
                                'text-lg font-bold',
                                'text-danger-700 dark:text-danger-300' => $overdueCount > 0,
                                'text-gray-400 dark:text-gray-500' => $overdueCount === 0,
                            ])>{{ $overdueCount > 0 ? $currency . ' ' . $overdueTotal : '—' }}</p>
                            <p class="text-[0.65rem] text-gray-400 dark:text-gray-500">{{ $overdueCount }} {{ $overdueCount === 1 ? 'item' : 'items' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Next 7 days --}}
                <div @class([
                    'rounded-xl border p-4',
                    'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5' => $weekCount > 0,
                    'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $weekCount === 0,
                ])>
                    <div class="flex items-center gap-3">
                        <div @class([
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                            'bg-warning-200 dark:bg-warning-500/20' => $weekCount > 0,
                            'bg-gray-200 dark:bg-white/10' => $weekCount === 0,
                        ])>
                            <x-filament::icon icon="heroicon-o-clock" @class([
                                'h-5 w-5',
                                'text-warning-600 dark:text-warning-400' => $weekCount > 0,
                                'text-gray-400 dark:text-gray-500' => $weekCount === 0,
                            ]) />
                        </div>
                        <div class="min-w-0">
                            <p @class([
                                'text-[0.65rem] font-semibold uppercase tracking-wide',
                                'text-warning-600 dark:text-warning-400' => $weekCount > 0,
                                'text-gray-400 dark:text-gray-500' => $weekCount === 0,
                            ])>Next 7 Days</p>
                            <p @class([
                                'text-lg font-bold',
                                'text-warning-700 dark:text-warning-300' => $weekCount > 0,
                                'text-gray-400 dark:text-gray-500' => $weekCount === 0,
                            ])>{{ $weekCount > 0 ? $currency . ' ' . $weekTotal : '—' }}</p>
                            <p class="text-[0.65rem] text-gray-400 dark:text-gray-500">{{ $weekCount }} {{ $weekCount === 1 ? 'item' : 'items' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Next 30 days --}}
                <div @class([
                    'rounded-xl border p-4',
                    'border-info-200 bg-info-50 dark:border-info-500/20 dark:bg-info-500/5' => $monthCount > 0,
                    'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $monthCount === 0,
                ])>
                    <div class="flex items-center gap-3">
                        <div @class([
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                            'bg-info-200 dark:bg-info-500/20' => $monthCount > 0,
                            'bg-gray-200 dark:bg-white/10' => $monthCount === 0,
                        ])>
                            <x-filament::icon icon="heroicon-o-calendar" @class([
                                'h-5 w-5',
                                'text-info-600 dark:text-info-400' => $monthCount > 0,
                                'text-gray-400 dark:text-gray-500' => $monthCount === 0,
                            ]) />
                        </div>
                        <div class="min-w-0">
                            <p @class([
                                'text-[0.65rem] font-semibold uppercase tracking-wide',
                                'text-info-600 dark:text-info-400' => $monthCount > 0,
                                'text-gray-400 dark:text-gray-500' => $monthCount === 0,
                            ])>Next 30 Days</p>
                            <p @class([
                                'text-lg font-bold',
                                'text-info-700 dark:text-info-300' => $monthCount > 0,
                                'text-gray-400 dark:text-gray-500' => $monthCount === 0,
                            ])>{{ $monthCount > 0 ? $currency . ' ' . $monthTotal : '—' }}</p>
                            <p class="text-[0.65rem] text-gray-400 dark:text-gray-500">{{ $monthCount }} {{ $monthCount === 1 ? 'item' : 'items' }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Detailed Items Table --}}
            @php
                $allItems = array_merge(
                    array_map(fn($i) => array_merge($i, ['section' => 'overdue']), $overdue),
                    array_map(fn($i) => array_merge($i, ['section' => 'week']), $thisWeek),
                    array_map(fn($i) => array_merge($i, ['section' => 'month']), $thisMonth),
                    array_map(fn($i) => array_merge($i, ['section' => 'pending']), $pending),
                );
            @endphp

            @if (count($allItems) > 0)
                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Type</th>
                                <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Reference</th>
                                <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Stage</th>
                                <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remaining</th>
                                <th class="px-4 py-3 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Due Date</th>
                                <th class="px-4 py-3 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Days</th>
                                <th class="px-4 py-3 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($allItems as $index => $item)
                                <tr @class([
                                    'border-b border-gray-100 dark:border-white/5',
                                    'bg-danger-50/30 dark:bg-danger-500/[0.03]' => $item['section'] === 'overdue',
                                    'bg-gray-50/50 dark:bg-white/[0.02]' => $item['section'] !== 'overdue' && $index % 2 === 1,
                                ])>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <span @class([
                                            'inline-flex items-center rounded-md px-2 py-1 text-xs font-medium',
                                            match ($item['doc_color']) {
                                                'primary' => 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-400',
                                                'info' => 'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-400',
                                                default => 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-400',
                                            },
                                        ])>{{ $item['doc_type'] }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 font-semibold text-gray-900 dark:text-white">
                                        {{ $item['reference'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-gray-400">
                                        @if ($item['percentage'])
                                            <span class="text-gray-400 dark:text-gray-500">{{ $item['percentage'] }}%</span>
                                        @endif
                                        {{ $item['label'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium">
                                        <span @class([
                                            'text-danger-600 dark:text-danger-400 font-bold' => $item['section'] === 'overdue',
                                            'text-warning-600 dark:text-warning-400' => $item['section'] === 'week',
                                            'text-gray-900 dark:text-white' => $item['section'] === 'month',
                                        ])>
                                            <span class="text-gray-400 dark:text-gray-500">{{ $item['currency'] }}</span>
                                            {{ $item['remaining'] }}
                                        </span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-center">
                                        <span @class([
                                            'font-semibold text-danger-600 dark:text-danger-400' => $item['section'] === 'overdue',
                                            'text-gray-600 dark:text-gray-400' => $item['section'] !== 'overdue',
                                        ])>{{ $item['due_date'] ?? '—' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-center">
                                        @if ($item['days_until'] !== null)
                                            @if ($item['days_until'] < 0)
                                                <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-[0.65rem] font-bold text-danger-700 dark:bg-danger-500/20 dark:text-danger-400">
                                                    {{ abs($item['days_until']) }}d overdue
                                                </span>
                                            @elseif ($item['days_until'] === 0)
                                                <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-[0.65rem] font-bold text-warning-700 dark:bg-warning-500/20 dark:text-warning-400">
                                                    Today
                                                </span>
                                            @else
                                                <span @class([
                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[0.65rem] font-medium',
                                                    'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400' => $item['days_until'] <= 7,
                                                    'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400' => $item['days_until'] > 7,
                                                ])>
                                                    in {{ $item['days_until'] }}d
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-center">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-[0.65rem] font-semibold',
                                            match ($item['status_color']) {
                                                'gray' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400',
                                                'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400',
                                                'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400',
                                                'success' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400',
                                                default => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400',
                                            },
                                        ])>{{ $item['status_label'] }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
