<x-filament-widgets::widget>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {{-- Product Value --}}
        <div class="rounded-lg border border-primary-200 bg-primary-50 p-3 dark:border-primary-500/20 dark:bg-primary-500/5">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-currency-dollar" class="h-4 w-4 text-primary-500" />
                <span class="text-xs font-medium text-primary-600 dark:text-primary-400">Product Value</span>
            </div>
            <p class="mt-1 text-lg font-bold text-primary-700 dark:text-primary-300">
                {{ $currency }} {{ $total_value }}
            </p>
        </div>

        {{-- Total Quantity --}}
        <div class="rounded-lg border border-info-200 bg-info-50 p-3 dark:border-info-500/20 dark:bg-info-500/5">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-cube" class="h-4 w-4 text-info-500" />
                <span class="text-xs font-medium text-info-600 dark:text-info-400">Quantity</span>
            </div>
            <p class="mt-1 text-lg font-bold text-info-700 dark:text-info-300">
                {{ $total_quantity }} pcs
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $product_count }} {{ Str::plural('product', $product_count) }}</p>
        </div>

        {{-- Freight Cost --}}
        <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/5">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-truck" class="h-4 w-4 text-warning-500" />
                <span class="text-xs font-medium text-warning-600 dark:text-warning-400">Freight</span>
            </div>
            <p class="mt-1 text-lg font-bold text-warning-700 dark:text-warning-300">
                @if($freight_cost)
                    {{ $currency }} {{ $freight_cost }}
                @else
                    —
                @endif
            </p>
        </div>

        {{-- Gross Weight --}}
        @if($total_weight)
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-scale" class="h-4 w-4 text-gray-500" />
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Gross Weight</span>
                </div>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ $total_weight }} kg
                </p>
            </div>
        @endif

        {{-- Volume --}}
        @if($total_volume)
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-cube-transparent" class="h-4 w-4 text-gray-500" />
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Volume</span>
                </div>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ $total_volume }} m&sup3;
                </p>
            </div>
        @endif

        {{-- Packages --}}
        @if($total_packages)
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-archive-box" class="h-4 w-4 text-gray-500" />
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Packages</span>
                </div>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ $total_packages }}
                </p>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
