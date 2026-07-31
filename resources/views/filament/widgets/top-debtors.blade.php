<x-filament-widgets::widget>
    <x-filament::section :heading="__('widgets.financial_dashboard.top_debtors_heading')">
        @if (count($debtors) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('widgets.financial_dashboard.top_debtors_empty') }}
            </p>
        @else
            <div class="space-y-3">
                @foreach ($debtors as $debtor)
                    <div class="flex items-center gap-3">
                        <span class="w-40 truncate text-sm text-gray-700 dark:text-gray-200">
                            {{ $debtor['name'] }}
                        </span>
                        <div class="h-2 flex-1 rounded-full bg-gray-100 dark:bg-gray-800">
                            <div
                                class="h-2 rounded-full bg-indigo-500"
                                style="width: {{ $debtor['percent'] }}%"
                            ></div>
                        </div>
                        <span class="w-32 text-end text-sm font-medium tabular-nums text-gray-900 dark:text-white">
                            {{ $baseCurrencyCode }} {{ $debtor['formatted'] }}
                        </span>
                    </div>
                @endforeach
            </div>

            @if (count($unconverted))
                <p class="mt-3 text-xs text-amber-600 dark:text-amber-400">
                    {{ __('widgets.financial_dashboard.conversion_warning', ['codes' => implode(', ', $unconverted)]) }}
                </p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
