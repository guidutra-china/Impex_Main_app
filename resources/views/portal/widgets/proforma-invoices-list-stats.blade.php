<x-filament-widgets::widget>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-filament::section>
            <div class="space-y-2">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-document-duplicate" class="h-5 w-5 text-gray-400" />
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Invoices</p>
                </div>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">{{ $total }}</p>
                <div class="flex gap-3 text-xs text-gray-400 dark:text-gray-500">
                    <span>{{ $pending }} pending</span>
                    <span>&middot;</span>
                    <span>{{ $confirmed }} confirmed</span>
                    <span>&middot;</span>
                    <span>{{ $finalized }} finalized</span>
                </div>
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
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-credit-card" class="h-5 w-5 text-success-500" />
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Payment Progress</p>
                        </div>
                        <span class="inline-flex items-center rounded-full bg-primary-50 px-2 py-0.5 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                            {{ $paymentProgress }}%
                        </span>
                    </div>
                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-success-500 transition-all duration-500" style="width: {{ min($paymentProgress, 100) }}%"></div>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span class="text-success-600 dark:text-success-400">Paid: {{ $currency }} {{ $totalPaid }}</span>
                        <span class="text-danger-600 dark:text-danger-400">Remaining: {{ $currency }} {{ $totalRemaining }}</span>
                    </div>
                </div>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-500" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Confirmed</p>
                    </div>
                    <p class="text-3xl font-bold text-success-600 dark:text-success-400">{{ $confirmed }}</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-lock-closed" class="h-5 w-5 text-primary-500" />
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Finalized</p>
                    </div>
                    <p class="text-3xl font-bold text-primary-600 dark:text-primary-400">{{ $finalized }}</p>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
