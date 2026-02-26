<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        {{-- Current Month Total --}}
        <x-filament::section>
            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ $currentMonthLabel }}
                    </p>
                    @if ($monthOverMonth['direction'] !== 'neutral')
                        <span @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                            'bg-danger-50 text-danger-600 dark:bg-danger-500/10 dark:text-danger-400' => $monthOverMonth['direction'] === 'up',
                            'bg-success-50 text-success-600 dark:bg-success-500/10 dark:text-success-400' => $monthOverMonth['direction'] === 'down',
                        ])>
                            <x-filament::icon
                                :icon="$monthOverMonth['direction'] === 'up' ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'"
                                class="h-3 w-3"
                            />
                            {{ $monthOverMonth['label'] }}
                        </span>
                    @endif
                </div>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $baseCurrencyCode }} {{ $currentMonth['total'] }}
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $currentMonth['count'] }} {{ __('widgets.expenses.entries') }}
                </p>
            </div>
        </x-filament::section>

        {{-- Previous Month --}}
        <x-filament::section>
            <div class="space-y-3">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ $previousMonthLabel }}
                </p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $baseCurrencyCode }} {{ $previousMonth['total'] }}
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $previousMonth['count'] }} {{ __('widgets.expenses.entries') }}
                </p>
            </div>
        </x-filament::section>

        {{-- Year to Date --}}
        <x-filament::section>
            <div class="space-y-3">
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                    {{ __('widgets.expenses.year_to_date') }}
                </p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $baseCurrencyCode }} {{ $yearToDate['total'] }}
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ __('widgets.expenses.monthly_average') }}: {{ $baseCurrencyCode }} {{ $yearToDate['monthly_avg'] }}
                </p>
            </div>
        </x-filament::section>
    </div>

    {{-- Category Breakdown --}}
    @if (count($currentMonth['categories']) > 0)
        <div class="mt-4">
            <x-filament::section>
                <x-slot name="heading">
                    {{ __('widgets.expenses.category_breakdown') }} — {{ $currentMonthLabel }}
                </x-slot>

                <div class="space-y-3">
                    @foreach ($currentMonth['categories'] as $cat)
                        <div class="flex items-center gap-3">
                            <div class="flex-shrink-0">
                                <x-filament::icon :icon="$cat['icon']" @class([
                                    'h-5 w-5',
                                    'text-primary-500' => $cat['color'] === 'primary',
                                    'text-danger-500' => $cat['color'] === 'danger',
                                    'text-success-500' => $cat['color'] === 'success',
                                    'text-warning-500' => $cat['color'] === 'warning',
                                    'text-info-500' => $cat['color'] === 'info',
                                    'text-gray-500' => $cat['color'] === 'gray',
                                ]) />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ $cat['label'] }}
                                    </p>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $baseCurrencyCode }} {{ $cat['amount'] }}
                                    </p>
                                </div>
                                <div class="mt-1 flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                        <div @class([
                                            'h-full rounded-full',
                                            'bg-primary-500' => $cat['color'] === 'primary',
                                            'bg-danger-500' => $cat['color'] === 'danger',
                                            'bg-success-500' => $cat['color'] === 'success',
                                            'bg-warning-500' => $cat['color'] === 'warning',
                                            'bg-info-500' => $cat['color'] === 'info',
                                            'bg-gray-400' => $cat['color'] === 'gray',
                                        ]) style="width: {{ $cat['percentage'] }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $cat['percentage'] }}%
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-widgets::widget>
