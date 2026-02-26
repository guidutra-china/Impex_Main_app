<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5 text-gray-400" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Payments</p>
                </div>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $total }}</p>
                <p class="text-sm text-gray-400 dark:text-gray-500">{{ $currency }} {{ $totalAmount }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Approved</p>
                </div>
                <p class="text-3xl font-bold text-success-600 dark:text-success-400">{{ $approved }}</p>
                <p class="text-sm text-success-500">{{ $currency }} {{ $approvedAmount }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 text-primary-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Allocated</p>
                </div>
                <p class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $currency }} {{ $allocatedAmount }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">Applied to invoices</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 {{ $unallocatedAmount !== '0.00' ? 'text-warning-500' : 'text-success-500' }}" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Unallocated</p>
                </div>
                <p class="text-2xl font-bold {{ $unallocatedAmount !== '0.00' ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400' }}">
                    {{ $currency }} {{ $unallocatedAmount }}
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $unallocatedAmount !== '0.00' ? 'Pending allocation' : 'Fully allocated' }}
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
