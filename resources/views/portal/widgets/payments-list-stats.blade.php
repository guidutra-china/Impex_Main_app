<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5 text-gray-400" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.list_stats.total_payments') }}</p>
                </div>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $total }}</p>
                @foreach ($totalAmount as $line)
                    <p class="text-sm text-gray-400 dark:text-gray-500">{{ $line }}</p>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.list_stats.approved') }}</p>
                </div>
                <p class="text-3xl font-bold text-success-600 dark:text-success-400">{{ $approved }}</p>
                @foreach ($approvedAmount as $line)
                    <p class="text-sm text-success-500">{{ $line }}</p>
                @endforeach
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 text-primary-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.list_stats.allocated') }}</p>
                </div>
                @foreach ($allocatedAmount as $line)
                    <p @class(['font-bold text-primary-600 dark:text-primary-400', 'text-2xl' => $loop->first, 'text-base' => ! $loop->first])>{{ $line }}</p>
                @endforeach
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ __('widgets.list_stats.applied_to_invoices') }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 {{ $hasUnallocated ? 'text-warning-500' : 'text-success-500' }}" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.list_stats.unallocated') }}</p>
                </div>
                @foreach ($unallocatedAmount as $line)
                    <p @class([
                        'font-bold',
                        'text-2xl' => $loop->first,
                        'text-base' => ! $loop->first,
                        'text-warning-600 dark:text-warning-400' => $hasUnallocated,
                        'text-success-600 dark:text-success-400' => ! $hasUnallocated,
                    ])>{{ $line }}</p>
                @endforeach
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $hasUnallocated ? __('widgets.list_stats.pending_allocation') : __('widgets.list_stats.fully_allocated') }}
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
