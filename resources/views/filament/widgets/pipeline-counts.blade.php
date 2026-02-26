<x-filament-widgets::widget>
    <x-filament::section
        heading="Operations Pipeline"
        icon="heroicon-o-arrows-right-left"
        :description="$totalActive . ' active item' . ($totalActive !== 1 ? 's' : '') . ' across all stages'"
    >
        {{-- Pipeline Flow --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($stages as $index => $stage)
                <div class="relative">
                    {{-- Stage Card --}}
                    <a href="{{ $stage['url'] }}" @class([
                        'group block rounded-lg border p-4 transition-all hover:shadow-md',
                        'border-info-200 bg-info-50/50 hover:bg-info-50 dark:border-info-500/20 dark:bg-info-500/5 dark:hover:bg-info-500/10' => $stage['color'] === 'info',
                        'border-primary-200 bg-primary-50/50 hover:bg-primary-50 dark:border-primary-500/20 dark:bg-primary-500/5 dark:hover:bg-primary-500/10' => $stage['color'] === 'primary',
                        'border-warning-200 bg-warning-50/50 hover:bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5 dark:hover:bg-warning-500/10' => $stage['color'] === 'warning',
                        'border-success-200 bg-success-50/50 hover:bg-success-50 dark:border-success-500/20 dark:bg-success-500/5 dark:hover:bg-success-500/10' => $stage['color'] === 'success',
                    ])>
                        {{-- Stage Number Badge --}}
                        <div class="mb-3 flex items-center justify-between">
                            <div @class([
                                'flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold text-white',
                                'bg-info-500' => $stage['color'] === 'info',
                                'bg-primary-500' => $stage['color'] === 'primary',
                                'bg-warning-500' => $stage['color'] === 'warning',
                                'bg-success-500' => $stage['color'] === 'success',
                            ])>
                                {{ $index + 1 }}
                            </div>
                            <x-filament::icon
                                :icon="$stage['icon']"
                                @class([
                                    'h-5 w-5',
                                    'text-info-400 dark:text-info-500' => $stage['color'] === 'info',
                                    'text-primary-400 dark:text-primary-500' => $stage['color'] === 'primary',
                                    'text-warning-400 dark:text-warning-500' => $stage['color'] === 'warning',
                                    'text-success-400 dark:text-success-500' => $stage['color'] === 'success',
                                ])
                            />
                        </div>

                        {{-- Count --}}
                        <p @class([
                            'text-3xl font-bold',
                            'text-info-700 dark:text-info-300' => $stage['color'] === 'info',
                            'text-primary-700 dark:text-primary-300' => $stage['color'] === 'primary',
                            'text-warning-700 dark:text-warning-300' => $stage['color'] === 'warning',
                            'text-success-700 dark:text-success-300' => $stage['color'] === 'success',
                        ])>
                            {{ $stage['count'] }}
                        </p>

                        {{-- Label --}}
                        <p @class([
                            'text-sm font-semibold',
                            'text-info-600 dark:text-info-400' => $stage['color'] === 'info',
                            'text-primary-600 dark:text-primary-400' => $stage['color'] === 'primary',
                            'text-warning-600 dark:text-warning-400' => $stage['color'] === 'warning',
                            'text-success-600 dark:text-success-400' => $stage['color'] === 'success',
                        ])>
                            {{ $stage['label'] }}
                        </p>

                        {{-- Detail --}}
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            {{ $stage['detail'] }}
                        </p>
                    </a>

                    {{-- Arrow connector (hidden on mobile, visible on lg) --}}
                    @if ($index < count($stages) - 1)
                        <div class="absolute -right-3 top-1/2 z-10 hidden -translate-y-1/2 lg:block">
                            <x-filament::icon
                                icon="heroicon-o-chevron-right"
                                class="h-5 w-5 text-gray-300 dark:text-gray-600"
                            />
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
