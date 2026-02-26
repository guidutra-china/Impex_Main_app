<x-filament-widgets::widget>
    <x-filament::section
        heading="Landed Cost Calculator"
        icon="heroicon-o-calculator"
        description="Complete cost analysis for this shipment"
        collapsible
    >
        {{-- Summary Cards --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {{-- FOB Cost --}}
            <div class="rounded-lg border border-primary-200 bg-primary-50 p-3 dark:border-primary-500/20 dark:bg-primary-500/5">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-cube" class="h-4 w-4 text-primary-500" />
                    <span class="text-xs font-medium text-primary-600 dark:text-primary-400">FOB Cost</span>
                </div>
                <p class="mt-1 text-lg font-bold text-primary-700 dark:text-primary-300">
                    {{ $currency }} {{ $summary['fob_cost_formatted'] }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['fob_percentage'] }}% of landed</p>
            </div>

            {{-- Additional Costs --}}
            <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/5">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-plus-circle" class="h-4 w-4 text-warning-500" />
                    <span class="text-xs font-medium text-warning-600 dark:text-warning-400">Additional Costs</span>
                </div>
                <p class="mt-1 text-lg font-bold text-warning-700 dark:text-warning-300">
                    {{ $currency }} {{ $costs['total_formatted'] }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['additional_percentage'] }}% of landed</p>
            </div>

            {{-- Total Landed Cost --}}
            <div class="rounded-lg border border-gray-300 bg-gray-100 p-3 dark:border-gray-600 dark:bg-gray-800">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-calculator" class="h-4 w-4 text-gray-600 dark:text-gray-400" />
                    <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Total Landed</span>
                </div>
                <p class="mt-1 text-lg font-bold text-gray-900 dark:text-white">
                    {{ $currency }} {{ $summary['total_landed_cost_formatted'] }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">FOB + All costs</p>
            </div>

            {{-- Gross Profit --}}
            <div @class([
                'rounded-lg border p-3',
                'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/5' => $margin['gross_profit_raw'] >= 0,
                'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/5' => $margin['gross_profit_raw'] < 0,
            ])>
                <div class="flex items-center gap-2">
                    <x-filament::icon
                        :icon="$margin['gross_profit_raw'] >= 0 ? 'heroicon-o-arrow-trending-up' : 'heroicon-o-arrow-trending-down'"
                        @class([
                            'h-4 w-4',
                            'text-success-500' => $margin['gross_profit_raw'] >= 0,
                            'text-danger-500' => $margin['gross_profit_raw'] < 0,
                        ])
                    />
                    <span @class([
                        'text-xs font-medium',
                        'text-success-600 dark:text-success-400' => $margin['gross_profit_raw'] >= 0,
                        'text-danger-600 dark:text-danger-400' => $margin['gross_profit_raw'] < 0,
                    ])>Gross Profit</span>
                </div>
                <p @class([
                    'mt-1 text-lg font-bold',
                    'text-success-700 dark:text-success-300' => $margin['gross_profit_raw'] >= 0,
                    'text-danger-700 dark:text-danger-300' => $margin['gross_profit_raw'] < 0,
                ])>
                    {{ $margin['gross_profit_raw'] < 0 ? '−' : '' }} {{ $currency }} {{ $margin['gross_profit_formatted'] }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $margin['margin_on_sale'] }}% on sale</p>
            </div>
        </div>

        {{-- Unit Metrics --}}
        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Landed / Unit</p>
                <p class="mt-1 text-sm font-bold text-gray-900 dark:text-white">{{ $currency }} {{ $summary['per_unit'] }}</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($summary['total_quantity']) }} units</p>
            </div>
            @if ($summary['total_weight'] > 0)
                <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Landed / KG</p>
                    <p class="mt-1 text-sm font-bold text-gray-900 dark:text-white">{{ $currency }} {{ $summary['per_kg'] }}</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500">{{ number_format($summary['total_weight'], 3) }} kg</p>
                </div>
            @endif
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Margin on Cost</p>
                <p @class([
                    'mt-1 text-sm font-bold',
                    'text-success-600 dark:text-success-400' => $margin['margin_on_cost'] >= 0,
                    'text-danger-600 dark:text-danger-400' => $margin['margin_on_cost'] < 0,
                ])>{{ $margin['margin_on_cost'] }}%</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">After all costs</p>
            </div>
            <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Margin Erosion</p>
                <p @class([
                    'mt-1 text-sm font-bold',
                    'text-warning-600 dark:text-warning-400' => abs($margin['margin_erosion']) > 0,
                    'text-gray-600 dark:text-gray-400' => $margin['margin_erosion'] == 0,
                ])>
                    {{ $margin['margin_erosion'] < 0 ? '−' : '' }}{{ number_format(abs($margin['margin_erosion']), 2) }}pp
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">FOB {{ $margin['fob_margin'] }}% → Landed {{ $margin['margin_on_cost'] }}%</p>
            </div>
        </div>

        {{-- Cost Breakdown by Category --}}
        @if (count($costs['groups']) > 0)
            <div class="mt-6">
                <h4 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Cost Breakdown</h4>
                <div class="space-y-2">
                    @foreach ($costs['groups'] as $group)
                        <div class="flex items-center gap-3">
                            <div class="flex w-36 items-center gap-2 sm:w-44">
                                <x-filament::icon :icon="$group['icon']" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">{{ $group['label'] }}</span>
                            </div>
                            <div class="flex-1">
                                <div class="h-4 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                    <div class="h-full rounded-full bg-primary-400 dark:bg-primary-500 transition-all"
                                         style="width: {{ $group['percentage'] }}%"></div>
                                </div>
                            </div>
                            <div class="w-28 text-right">
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ $currency }} {{ $group['total_formatted'] }}</span>
                            </div>
                            <div class="w-12 text-right">
                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ $group['percentage'] }}%</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Billable To Summary --}}
        @if ($costs['by_billable']['client_raw'] > 0 || $costs['by_billable']['supplier_raw'] > 0 || $costs['by_billable']['company_raw'] > 0)
            <div class="mt-4 flex flex-wrap gap-3">
                @if ($costs['by_billable']['client_raw'] > 0)
                    <div class="flex items-center gap-2 rounded-lg bg-success-50 px-3 py-2 dark:bg-success-500/10">
                        <x-filament::icon icon="heroicon-o-arrow-up-right" class="h-3.5 w-3.5 text-success-500" />
                        <span class="text-xs text-success-700 dark:text-success-400">
                            Client: {{ $currency }} {{ $costs['by_billable']['client'] }}
                        </span>
                    </div>
                @endif
                @if ($costs['by_billable']['supplier_raw'] > 0)
                    <div class="flex items-center gap-2 rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-500/10">
                        <x-filament::icon icon="heroicon-o-arrow-down-left" class="h-3.5 w-3.5 text-warning-500" />
                        <span class="text-xs text-warning-700 dark:text-warning-400">
                            Supplier: {{ $currency }} {{ $costs['by_billable']['supplier'] }}
                        </span>
                    </div>
                @endif
                @if ($costs['by_billable']['company_raw'] > 0)
                    <div class="flex items-center gap-2 rounded-lg bg-danger-50 px-3 py-2 dark:bg-danger-500/10">
                        <x-filament::icon icon="heroicon-o-building-office" class="h-3.5 w-3.5 text-danger-500" />
                        <span class="text-xs text-danger-700 dark:text-danger-400">
                            Company: {{ $currency }} {{ $costs['by_billable']['company'] }}
                        </span>
                    </div>
                @endif
            </div>
        @endif

        {{-- Item Detail Table --}}
        <div class="mt-6">
            <h4 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Item Breakdown</h4>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">Product</th>
                            <th class="pb-2 text-center text-xs font-semibold text-gray-500 dark:text-gray-400">Qty</th>
                            <th class="pb-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">Unit Cost</th>
                            <th class="pb-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">Unit Price</th>
                            <th class="pb-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">FOB Total</th>
                            <th class="pb-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">Sell Total</th>
                            <th class="pb-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">Weight</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($items['rows'] as $row)
                            <tr>
                                <td class="py-2 pr-3">
                                    <p class="font-medium text-gray-900 dark:text-white">{{ $row['product'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        PI: {{ $row['pi_ref'] }} · PO: {{ $row['po_ref'] }}
                                    </p>
                                </td>
                                <td class="py-2 text-center text-gray-700 dark:text-gray-300">
                                    {{ number_format($row['quantity']) }} {{ $row['unit'] }}
                                </td>
                                <td class="py-2 text-right text-gray-700 dark:text-gray-300">
                                    {{ $currency }} {{ $row['unit_cost'] }}
                                </td>
                                <td class="py-2 text-right text-gray-700 dark:text-gray-300">
                                    {{ $currency }} {{ $row['unit_price'] }}
                                </td>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">
                                    {{ $currency }} {{ $row['fob_total'] }}
                                </td>
                                <td class="py-2 text-right font-medium text-gray-900 dark:text-white">
                                    {{ $currency }} {{ $row['sell_total'] }}
                                </td>
                                <td class="py-2 text-right text-gray-500 dark:text-gray-400">
                                    {{ $row['weight'] }} kg
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-300 dark:border-gray-600">
                            <td class="pt-2 font-bold text-gray-900 dark:text-white">Total</td>
                            <td class="pt-2 text-center font-bold text-gray-900 dark:text-white">
                                {{ number_format($items['total_quantity']) }}
                            </td>
                            <td class="pt-2"></td>
                            <td class="pt-2"></td>
                            <td class="pt-2 text-right font-bold text-gray-900 dark:text-white">
                                {{ $currency }} {{ $items['total_fob_cost_formatted'] }}
                            </td>
                            <td class="pt-2 text-right font-bold text-gray-900 dark:text-white">
                                {{ $currency }} {{ $items['total_selling_value_formatted'] }}
                            </td>
                            <td class="pt-2 text-right font-bold text-gray-500 dark:text-gray-400">
                                {{ $items['total_weight_formatted'] }} kg
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Additional Cost Details --}}
        @if (count($costs['details']) > 0)
            <div class="mt-6">
                <h4 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Additional Cost Details</h4>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="pb-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">Type</th>
                                <th class="pb-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">Description</th>
                                <th class="pb-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400">Supplier</th>
                                <th class="pb-2 text-right text-xs font-semibold text-gray-500 dark:text-gray-400">Amount</th>
                                <th class="pb-2 text-center text-xs font-semibold text-gray-500 dark:text-gray-400">Billable To</th>
                                <th class="pb-2 text-center text-xs font-semibold text-gray-500 dark:text-gray-400">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @foreach ($costs['details'] as $detail)
                                <tr>
                                    <td class="py-2 pr-3 font-medium text-gray-900 dark:text-white">
                                        {{ $detail['type'] }}
                                    </td>
                                    <td class="py-2 pr-3 text-gray-700 dark:text-gray-300">
                                        {{ $detail['description'] ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3 text-gray-700 dark:text-gray-300">
                                        {{ $detail['supplier'] ?? '—' }}
                                    </td>
                                    <td class="py-2 text-right">
                                        <p class="font-medium text-gray-900 dark:text-white">
                                            {{ $currency }} {{ $detail['converted_amount'] }}
                                        </p>
                                        @if ($detail['original_currency'] !== $currency)
                                            <p class="text-xs text-gray-400 dark:text-gray-500">
                                                {{ $detail['original_currency'] }} {{ $detail['original_amount'] }}
                                            </p>
                                        @endif
                                    </td>
                                    <td class="py-2 text-center">
                                        <x-filament::badge :color="$detail['billable_to_color']" size="sm">
                                            {{ $detail['billable_to'] }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="py-2 text-center">
                                        <x-filament::badge :color="$detail['status_color']" size="sm">
                                            {{ $detail['status'] }}
                                        </x-filament::badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Margin Comparison --}}
        <div class="mt-6 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-800/50">
            <h4 class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Margin Analysis</h4>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="text-center">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Selling Value</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ $currency }} {{ $margin['selling_value_formatted'] }}
                    </p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Total Landed Cost</p>
                    <p class="text-lg font-bold text-gray-900 dark:text-white">
                        {{ $currency }} {{ $margin['landed_cost_formatted'] }}
                    </p>
                </div>
                <div class="text-center">
                    <p class="text-xs text-gray-500 dark:text-gray-400">Gross Profit</p>
                    <p @class([
                        'text-lg font-bold',
                        'text-success-600 dark:text-success-400' => $margin['gross_profit_raw'] >= 0,
                        'text-danger-600 dark:text-danger-400' => $margin['gross_profit_raw'] < 0,
                    ])>
                        {{ $margin['gross_profit_raw'] < 0 ? '−' : '' }} {{ $currency }} {{ $margin['gross_profit_formatted'] }}
                        <span class="text-sm font-normal">({{ $margin['margin_on_sale'] }}%)</span>
                    </p>
                </div>
            </div>

            {{-- Visual margin comparison bar --}}
            <div class="mt-4">
                <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                    <span>FOB Margin: {{ $margin['fob_margin'] }}%</span>
                    <span>Landed Margin: {{ $margin['margin_on_cost'] }}%</span>
                </div>
                <div class="mt-1 flex h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    @php
                        $fobWidth = min(max($margin['fob_margin'], 0), 100);
                        $landedWidth = min(max($margin['margin_on_cost'], 0), 100);
                    @endphp
                    <div class="h-full bg-primary-300 dark:bg-primary-600 transition-all"
                         style="width: {{ $fobWidth }}%"
                         title="FOB Margin: {{ $margin['fob_margin'] }}%"></div>
                </div>
                <div class="mt-1 flex h-3 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                    <div @class([
                        'h-full transition-all',
                        'bg-success-400 dark:bg-success-500' => $margin['margin_on_cost'] >= 0,
                        'bg-danger-400 dark:bg-danger-500' => $margin['margin_on_cost'] < 0,
                    ])
                         style="width: {{ $landedWidth }}%"
                         title="Landed Margin: {{ $margin['margin_on_cost'] }}%"></div>
                </div>
                <div class="mt-1 flex items-center gap-4 text-xs">
                    <div class="flex items-center gap-1.5">
                        <span class="h-2 w-2 rounded-full bg-primary-300 dark:bg-primary-600"></span>
                        <span class="text-gray-500 dark:text-gray-400">FOB Margin</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span @class([
                            'h-2 w-2 rounded-full',
                            'bg-success-400 dark:bg-success-500' => $margin['margin_on_cost'] >= 0,
                            'bg-danger-400 dark:bg-danger-500' => $margin['margin_on_cost'] < 0,
                        ])></span>
                        <span class="text-gray-500 dark:text-gray-400">Landed Margin</span>
                    </div>
                    @if ($margin['margin_erosion'] != 0)
                        <span class="text-warning-600 dark:text-warning-400">
                            Erosion: {{ number_format(abs($margin['margin_erosion']), 2) }}pp
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
