<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-cube" class="h-5 w-5 text-gray-400" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Shipments</p>
                </div>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $total }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 text-primary-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Active</p>
                </div>
                <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $active }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-truck" class="h-5 w-5 text-info-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">In Transit</p>
                </div>
                <p class="text-3xl font-bold text-info-600 dark:text-info-400">{{ $inTransit }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5 text-success-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Arrived</p>
                </div>
                <p class="text-3xl font-bold text-success-600 dark:text-success-400">{{ $arrived }}</p>
            </div>
        </x-filament::section>
    </div>

    @if (count($statusBreakdown) > 0)
        <div class="mt-4">
            <x-filament::section>
                <x-slot name="heading">Status Breakdown</x-slot>
                <div class="space-y-3">
                    @foreach ($statusBreakdown as $status)
                        <div class="flex items-center gap-3">
                            <div class="flex-shrink-0">
                                <x-filament::icon :icon="$status['icon']" @class([
                                    'h-5 w-5',
                                    'text-primary-500' => $status['color'] === 'primary',
                                    'text-danger-500' => $status['color'] === 'danger',
                                    'text-success-500' => $status['color'] === 'success',
                                    'text-warning-500' => $status['color'] === 'warning',
                                    'text-info-500' => $status['color'] === 'info',
                                    'text-gray-500' => $status['color'] === 'gray',
                                ]) />
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between">
                                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $status['label'] }}</p>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $status['count'] }}</p>
                                </div>
                                <div class="mt-1 flex items-center gap-2">
                                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                                        <div @class([
                                            'h-full rounded-full',
                                            'bg-primary-500' => $status['color'] === 'primary',
                                            'bg-danger-500' => $status['color'] === 'danger',
                                            'bg-success-500' => $status['color'] === 'success',
                                            'bg-warning-500' => $status['color'] === 'warning',
                                            'bg-info-500' => $status['color'] === 'info',
                                            'bg-gray-400' => $status['color'] === 'gray',
                                        ]) style="width: {{ $status['percentage'] }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">{{ $status['percentage'] }}%</span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>
    @endif
</x-filament-widgets::widget>
