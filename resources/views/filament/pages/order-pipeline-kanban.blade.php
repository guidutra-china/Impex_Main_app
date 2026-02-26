<x-filament-panels::page>
    <div class="flex gap-4 overflow-x-auto pb-4" x-data="{ dragging: null }">
        @foreach ($this->getColumns() as $column)
            <div class="flex-shrink-0 w-80">
                {{-- Column Header --}}
                <div @class([
                    'flex items-center justify-between rounded-t-xl px-4 py-3',
                    'bg-gray-100 dark:bg-gray-800' => $column['color'] === 'gray',
                    'bg-info-50 dark:bg-info-500/10' => $column['color'] === 'info',
                    'bg-primary-50 dark:bg-primary-500/10' => $column['color'] === 'primary',
                    'bg-warning-50 dark:bg-warning-500/10' => $column['color'] === 'warning',
                    'bg-success-50 dark:bg-success-500/10' => $column['color'] === 'success',
                ])>
                    <h3 @class([
                        'text-sm font-bold',
                        'text-gray-700 dark:text-gray-300' => $column['color'] === 'gray',
                        'text-info-700 dark:text-info-400' => $column['color'] === 'info',
                        'text-primary-700 dark:text-primary-400' => $column['color'] === 'primary',
                        'text-warning-700 dark:text-warning-400' => $column['color'] === 'warning',
                        'text-success-700 dark:text-success-400' => $column['color'] === 'success',
                    ])>
                        {{ $column['title'] }}
                    </h3>
                    <span @class([
                        'inline-flex items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold',
                        'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $column['color'] === 'gray',
                        'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-400' => $column['color'] === 'info',
                        'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-400' => $column['color'] === 'primary',
                        'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400' => $column['color'] === 'warning',
                        'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400' => $column['color'] === 'success',
                    ])>
                        {{ $column['count'] }}
                    </span>
                </div>

                {{-- Cards Container --}}
                <div class="space-y-2 rounded-b-xl bg-gray-50 p-2 dark:bg-gray-900" style="min-height: 200px; max-height: 75vh; overflow-y: auto;">
                    @forelse ($column['cards'] as $card)
                        <a
                            href="{{ $card['url'] }}"
                            class="block rounded-lg border border-gray-200 bg-white p-3 shadow-sm transition-all hover:shadow-md hover:border-primary-300 dark:border-gray-700 dark:bg-gray-800 dark:hover:border-primary-600"
                        >
                            {{-- Header: Reference + Days --}}
                            <div class="flex items-center justify-between mb-1.5">
                                <span class="text-sm font-bold text-gray-900 dark:text-white">
                                    {{ $card['reference'] }}
                                </span>
                                @if (isset($card['days_open']) && $card['days_open'] !== null)
                                    <span @class([
                                        'text-xs font-medium px-1.5 py-0.5 rounded',
                                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' => $card['days_open'] <= 7,
                                        'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400' => $card['days_open'] > 7 && $card['days_open'] <= 30,
                                        'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400' => $card['days_open'] > 30,
                                    ])>
                                        {{ $card['days_open'] }}d
                                    </span>
                                @endif
                            </div>

                            {{-- Company --}}
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate mb-1.5">
                                {{ $card['company'] }}
                            </p>

                            {{-- Subtitle / Status --}}
                            <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">
                                {{ $card['subtitle'] ?? '' }}
                            </p>

                            {{-- Value + Payment Progress --}}
                            @if ($card['value'] || $card['payment_progress'] !== null)
                                <div class="border-t border-gray-100 dark:border-gray-700 pt-2 mt-1">
                                    @if ($card['value'])
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-gray-500 dark:text-gray-400">Value</span>
                                            <span class="text-xs font-semibold text-gray-900 dark:text-white">{{ $card['value'] }}</span>
                                        </div>
                                    @endif

                                    @if ($card['payment_progress'] !== null)
                                        <div class="mt-1">
                                            <div class="flex items-center justify-between mb-0.5">
                                                <span class="text-xs text-gray-500 dark:text-gray-400">Paid</span>
                                                <span @class([
                                                    'text-xs font-semibold',
                                                    'text-success-600 dark:text-success-400' => $card['payment_progress'] >= 100,
                                                    'text-warning-600 dark:text-warning-400' => $card['payment_progress'] > 0 && $card['payment_progress'] < 100,
                                                    'text-gray-500 dark:text-gray-400' => $card['payment_progress'] == 0,
                                                ])>
                                                    {{ number_format($card['payment_progress'], 1) }}%
                                                </span>
                                            </div>
                                            <div class="h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                                                <div
                                                    @class([
                                                        'h-1.5 rounded-full transition-all',
                                                        'bg-success-500' => $card['payment_progress'] >= 100,
                                                        'bg-warning-500' => $card['payment_progress'] > 0 && $card['payment_progress'] < 100,
                                                        'bg-gray-300 dark:bg-gray-600' => $card['payment_progress'] == 0,
                                                    ])
                                                    style="width: {{ min($card['payment_progress'], 100) }}%"
                                                ></div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            @endif

                            {{-- Alerts --}}
                            @if (!empty($card['has_overdue']))
                                <div class="mt-2 flex items-center gap-1 text-danger-600 dark:text-danger-400">
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3.5 w-3.5" />
                                    <span class="text-xs font-medium">Overdue payment</span>
                                </div>
                            @endif

                            @if (isset($card['days_since_update']) && $card['days_since_update'] > 15)
                                <div class="mt-1 flex items-center gap-1 text-warning-600 dark:text-warning-400">
                                    <x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5" />
                                    <span class="text-xs font-medium">No update for {{ $card['days_since_update'] }}d</span>
                                </div>
                            @endif

                            @if (isset($card['eta']))
                                <div class="mt-1 flex items-center gap-1 text-info-600 dark:text-info-400">
                                    <x-filament::icon icon="heroicon-m-calendar" class="h-3.5 w-3.5" />
                                    <span class="text-xs font-medium">ETA: {{ $card['eta'] }}</span>
                                </div>
                            @endif
                        </a>
                    @empty
                        <div class="flex items-center justify-center py-8">
                            <p class="text-xs text-gray-400 dark:text-gray-500">No items</p>
                        </div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
