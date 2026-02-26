<x-filament-widgets::widget>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-{{ count($cards) }}">
        @foreach ($cards as $card)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                        'bg-primary-100 dark:bg-primary-500/20' => $card['color'] === 'primary',
                        'bg-success-100 dark:bg-success-500/20' => $card['color'] === 'success',
                        'bg-warning-100 dark:bg-warning-500/20' => $card['color'] === 'warning',
                        'bg-danger-100 dark:bg-danger-500/20' => $card['color'] === 'danger',
                        'bg-info-100 dark:bg-info-500/20' => $card['color'] === 'info',
                        'bg-gray-100 dark:bg-white/10' => $card['color'] === 'gray',
                    ])>
                        <x-filament::icon :icon="$card['icon']" @class([
                            'h-5 w-5',
                            'text-primary-600 dark:text-primary-400' => $card['color'] === 'primary',
                            'text-success-600 dark:text-success-400' => $card['color'] === 'success',
                            'text-warning-600 dark:text-warning-400' => $card['color'] === 'warning',
                            'text-danger-600 dark:text-danger-400' => $card['color'] === 'danger',
                            'text-info-600 dark:text-info-400' => $card['color'] === 'info',
                            'text-gray-500 dark:text-gray-400' => $card['color'] === 'gray',
                        ]) />
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                        <p @class([
                            'truncate text-lg font-bold',
                            'text-primary-600 dark:text-primary-400' => $card['color'] === 'primary',
                            'text-success-600 dark:text-success-400' => $card['color'] === 'success',
                            'text-warning-600 dark:text-warning-400' => $card['color'] === 'warning',
                            'text-danger-600 dark:text-danger-400' => $card['color'] === 'danger',
                            'text-info-600 dark:text-info-400' => $card['color'] === 'info',
                            'text-gray-900 dark:text-white' => $card['color'] === 'gray',
                        ])>{{ $card['value'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
