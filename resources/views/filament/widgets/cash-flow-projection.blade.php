<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('widgets.cash_flow.heading')"
        icon="heroicon-o-chart-bar"
        :description="__('widgets.cash_flow.description')"
        collapsible
    >
        {{-- Conversion Warning --}}
        @if ($has_conversion_warning ?? false)
            <div class="mb-4 flex items-start gap-2 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 dark:border-warning-500/30 dark:bg-warning-500/5">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning-500" />
                <div>
                    <p class="text-xs font-medium text-warning-700 dark:text-warning-400">
                        {{ __('widgets.expenses.missing_exchange_rate') }} — Some amounts excluded from totals.
                    </p>
                    <p class="text-xs text-warning-600 dark:text-warning-500">
                        Missing rates for: {{ implode(', ', $unconverted_currencies ?? []) }}
                    </p>
                </div>
            </div>
        @endif

        {{-- Projection Table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('widgets.cash_flow.period') }}
                        </th>
                        <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-success-600 dark:text-success-400">
                            <div class="flex items-center justify-end gap-1">
                                <x-filament::icon icon="heroicon-o-arrow-down-left" class="h-3.5 w-3.5" />
                                {{ __('widgets.cash_flow.inflow_pi') }}
                            </div>
                        </th>
                        <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-danger-600 dark:text-danger-400">
                            <div class="flex items-center justify-end gap-1">
                                <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-3.5 w-3.5" />
                                {{ __('widgets.cash_flow.outflow_po') }}
                            </div>
                        </th>
                        <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                            {{ __('widgets.cash_flow.net') }}
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach ($projection as $period)
                        <tr @class([
                            'transition-colors',
                            'bg-danger-50/50 dark:bg-danger-500/5' => $period['label'] === 'Overdue' && ($period['inflow_raw'] > 0 || $period['outflow_raw'] > 0),
                        ])>
                            {{-- Period --}}
                            <td class="py-3 pr-4">
                                <div class="flex items-center gap-2">
                                    @if ($period['label'] === 'Overdue')
                                        <span class="flex h-2 w-2 rounded-full bg-danger-500"></span>
                                    @elseif ($period['label'] === 'This Week')
                                        <span class="flex h-2 w-2 rounded-full bg-warning-500"></span>
                                    @elseif ($period['label'] === 'Next Week')
                                        <span class="flex h-2 w-2 rounded-full bg-primary-500"></span>
                                    @else
                                        <span class="flex h-2 w-2 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                    @endif
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white">{{ $period['label'] }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $period['range'] }}</p>
                                    </div>
                                </div>
                            </td>

                            {{-- Inflow --}}
                            <td class="py-3 text-right">
                                @if ($period['inflow_raw'] > 0)
                                    <p class="font-semibold text-success-600 dark:text-success-400">
                                        {{ $baseCurrencyCode }} {{ $period['inflow'] }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $period['inflow_count'] }} item{{ $period['inflow_count'] !== 1 ? 's' : '' }}
                                        @if ($period['overdue_inflow'] > 0)
                                            <span class="text-danger-500">({{ $period['overdue_inflow'] }} overdue)</span>
                                        @endif
                                    </p>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>

                            {{-- Outflow --}}
                            <td class="py-3 text-right">
                                @if ($period['outflow_raw'] > 0)
                                    <p class="font-semibold text-danger-600 dark:text-danger-400">
                                        {{ $baseCurrencyCode }} {{ $period['outflow'] }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $period['outflow_count'] }} item{{ $period['outflow_count'] !== 1 ? 's' : '' }}
                                        @if ($period['overdue_outflow'] > 0)
                                            <span class="text-danger-500">({{ $period['overdue_outflow'] }} overdue)</span>
                                        @endif
                                    </p>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>

                            {{-- Net --}}
                            <td class="py-3 text-right">
                                @if ($period['inflow_raw'] > 0 || $period['outflow_raw'] > 0)
                                    <p @class([
                                        'font-bold',
                                        'text-success-600 dark:text-success-400' => $period['net_raw'] >= 0,
                                        'text-danger-600 dark:text-danger-400' => $period['net_raw'] < 0,
                                    ])>
                                        {{ $period['net_raw'] < 0 ? '−' : '+' }} {{ $baseCurrencyCode }} {{ $period['net'] }}
                                    </p>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>

                {{-- Totals Footer --}}
                <tfoot>
                    <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                        <td class="pt-3 pr-4">
                            <p class="font-bold text-gray-900 dark:text-white">Total (90 days)</p>
                        </td>
                        <td class="pt-3 text-right">
                            <p class="font-bold text-success-600 dark:text-success-400">
                                {{ $baseCurrencyCode }} {{ $totals['inflow'] }}
                            </p>
                        </td>
                        <td class="pt-3 text-right">
                            <p class="font-bold text-danger-600 dark:text-danger-400">
                                {{ $baseCurrencyCode }} {{ $totals['outflow'] }}
                            </p>
                        </td>
                        <td class="pt-3 text-right">
                            <p @class([
                                'font-bold text-lg',
                                'text-success-600 dark:text-success-400' => $totals['net_raw'] >= 0,
                                'text-danger-600 dark:text-danger-400' => $totals['net_raw'] < 0,
                            ])>
                                {{ $totals['net_raw'] < 0 ? '−' : '+' }} {{ $baseCurrencyCode }} {{ $totals['net'] }}
                            </p>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Unscheduled Warning --}}
        @if ($unscheduled['inflow_raw'] > 0 || $unscheduled['outflow_raw'] > 0)
            <div class="mt-4 rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/5">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-warning-500" />
                    <span class="text-xs font-semibold text-warning-700 dark:text-warning-400">Unscheduled Payments (no due date)</span>
                </div>
                <div class="mt-2 flex gap-6 text-xs">
                    @if ($unscheduled['inflow_raw'] > 0)
                        <span class="text-success-600 dark:text-success-400">
                            {{ __('widgets.cash_flow.inflow') }}: {{ $baseCurrencyCode }} {{ $unscheduled['inflow'] }}
                        </span>
                    @endif
                    @if ($unscheduled['outflow_raw'] > 0)
                        <span class="text-danger-600 dark:text-danger-400">
                            {{ __('widgets.cash_flow.outflow') }}: {{ $baseCurrencyCode }} {{ $unscheduled['outflow'] }}
                        </span>
                    @endif
                </div>
                <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">
                    {{ __('widgets.cash_flow.no_due_date_warning') }}
                </p>
            </div>
        @endif

        {{-- Visual Bar Chart --}}
        @php
            $maxAmount = collect($projection)->max(fn ($p) => max($p['inflow_raw'], $p['outflow_raw']));
            $maxAmount = max($maxAmount, 1);
        @endphp

        <div class="mt-6 space-y-3">
            <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Visual Overview</p>
            @foreach ($projection as $period)
                @if ($period['inflow_raw'] > 0 || $period['outflow_raw'] > 0)
                    <div>
                        <div class="mb-1 flex items-center justify-between">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $period['label'] }}</span>
                            <span @class([
                                'text-xs font-bold',
                                'text-success-600 dark:text-success-400' => $period['net_raw'] >= 0,
                                'text-danger-600 dark:text-danger-400' => $period['net_raw'] < 0,
                            ])>
                                {{ $period['net_raw'] < 0 ? '−' : '+' }} {{ $baseCurrencyCode }} {{ $period['net'] }}
                            </span>
                        </div>
                        <div class="flex gap-1">
                            {{-- Inflow bar --}}
                            <div class="h-3 rounded-l-full bg-success-400 dark:bg-success-500"
                                 style="width: {{ max(($period['inflow_raw'] / $maxAmount) * 100, 0.5) }}%"
                                 title="Inflow: {{ $baseCurrencyCode }} {{ $period['inflow'] }}"
                            ></div>
                            {{-- Outflow bar --}}
                            <div class="h-3 rounded-r-full bg-danger-400 dark:bg-danger-500"
                                 style="width: {{ max(($period['outflow_raw'] / $maxAmount) * 100, 0.5) }}%"
                                 title="Outflow: {{ $baseCurrencyCode }} {{ $period['outflow'] }}"
                            ></div>
                        </div>
                    </div>
                @endif
            @endforeach

            {{-- Legend --}}
            <div class="flex items-center gap-4 pt-2">
                <div class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-full bg-success-400 dark:bg-success-500"></span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Inflow (from clients)</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <span class="h-2.5 w-2.5 rounded-full bg-danger-400 dark:bg-danger-500"></span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">Outflow (to suppliers)</span>
                </div>
            </div>
        </div>

        {{-- Conversion note --}}
        <p class="mt-4 text-center text-xs text-gray-400 dark:text-gray-500">
            All values converted to {{ $baseCurrencyCode }} using latest approved exchange rates
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
