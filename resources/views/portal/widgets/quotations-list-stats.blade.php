<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 {{ $showFinancial ? 'sm:grid-cols-3 lg:grid-cols-5' : 'sm:grid-cols-4' }}">
        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5 text-gray-400" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Quotations</p>
                </div>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $total }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-warning-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pending</p>
                </div>
                <p class="text-3xl font-bold text-warning-600 dark:text-warning-400">{{ $pending }}</p>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Approved</p>
                </div>
                <p class="text-3xl font-bold text-success-600 dark:text-success-400">{{ $approved }}</p>
            </div>
        </x-filament::section>

        @if ($showFinancial && $totalValue)
            <x-filament::section>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5 text-primary-500" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Value</p>
                    </div>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white">{{ $currency }} {{ $totalValue }}</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-currency-dollar" class="h-5 w-5 text-success-500" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Approved Value</p>
                    </div>
                    <p class="text-2xl font-bold text-success-600 dark:text-success-400">{{ $currency }} {{ $approvedValue }}</p>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-x-circle" class="h-5 w-5 text-danger-500" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Rejected</p>
                    </div>
                    <p class="text-3xl font-bold text-danger-600 dark:text-danger-400">{{ $rejected }}</p>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
