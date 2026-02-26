<x-filament-widgets::widget>
    {{-- Alerts Banner --}}
    @if (count($alerts) > 0)
        <div class="mb-4 flex flex-wrap gap-2">
            @foreach ($alerts as $alert)
                <div @class([
                    'flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium',
                    'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400' => $alert['type'] === 'danger',
                    'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => $alert['type'] === 'warning',
                    'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => $alert['type'] === 'primary',
                ])>
                    <x-filament::icon
                        :icon="$alert['icon']"
                        class="h-4 w-4"
                    />
                    {{ $alert['text'] }}
                </div>
            @endforeach
        </div>
    @endif

    {{-- Main Financial Grid --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {{-- Receivables Section --}}
        <x-filament::section
            :heading="__('widgets.financial_stats.receivables')"
            icon="heroicon-o-arrow-down-left"
            :description="__('widgets.financial_stats.from_clients')"
        >
            <div class="space-y-4">
                {{-- Conversion Warning --}}
                @if ($receivables['has_conversion_warning'] ?? false)
                    <div class="flex items-start gap-2 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 dark:border-warning-500/30 dark:bg-warning-500/5">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning-500" />
                        <div>
                            <p class="text-xs font-medium text-warning-700 dark:text-warning-400">{{ __('widgets.expenses.missing_exchange_rate') }}</p>
                            @foreach ($receivables['unconverted'] ?? [] as $line)
                                <p class="text-xs text-warning-600 dark:text-warning-500">+ {{ $line }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Cards --}}
                <div class="grid grid-cols-2 gap-3">
                    {{-- Outstanding --}}
                    <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 text-warning-500" />
                            <span class="text-xs font-medium text-warning-600 dark:text-warning-400">Outstanding</span>
                        </div>
                        <p class="mt-1 text-lg font-bold text-warning-700 dark:text-warning-300">
                            {{ $baseCurrencyCode }} {{ $receivables['outstanding'] }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $receivables['open_documents'] }} open PI{{ $receivables['open_documents'] !== 1 ? 's' : '' }}
                        </p>
                    </div>

                    {{-- Received --}}
                    <div class="rounded-lg border border-success-200 bg-success-50 p-3 dark:border-success-500/20 dark:bg-success-500/5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4 text-success-500" />
                            <span class="text-xs font-medium text-success-600 dark:text-success-400">Received</span>
                        </div>
                        <p class="mt-1 text-lg font-bold text-success-700 dark:text-success-300">
                            {{ $baseCurrencyCode }} {{ $receivables['received'] }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Approved inbound</p>
                    </div>
                </div>

                {{-- Overdue highlight --}}
                @if ($receivables['overdue_raw'] > 0)
                    <div class="flex items-center justify-between rounded-lg border border-danger-200 bg-danger-50 px-3 py-2 dark:border-danger-500/20 dark:bg-danger-500/5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-danger-500" />
                            <span class="text-xs font-medium text-danger-700 dark:text-danger-400">Overdue</span>
                        </div>
                        <span class="text-sm font-bold text-danger-700 dark:text-danger-300">
                            {{ $baseCurrencyCode }} {{ $receivables['overdue'] }}
                        </span>
                    </div>
                @endif

                {{-- Currency breakdown --}}
                @if (count($receivables['by_currency']) > 1)
                    <div class="border-t border-gray-200 pt-3 dark:border-gray-700">
                        <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">By Currency</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($receivables['by_currency'] as $item)
                                <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ $item['code'] }} {{ $item['amount'] }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- Payables Section --}}
        <x-filament::section
            :heading="__('widgets.financial_stats.payables')"
            icon="heroicon-o-arrow-up-right"
            :description="__('widgets.financial_stats.to_suppliers')"
        >
            <div class="space-y-4">
                {{-- Conversion Warning --}}
                @if ($payables['has_conversion_warning'] ?? false)
                    <div class="flex items-start gap-2 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 dark:border-warning-500/30 dark:bg-warning-500/5">
                        <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning-500" />
                        <div>
                            <p class="text-xs font-medium text-warning-700 dark:text-warning-400">{{ __('widgets.expenses.missing_exchange_rate') }}</p>
                            @foreach ($payables['unconverted'] ?? [] as $line)
                                <p class="text-xs text-warning-600 dark:text-warning-500">+ {{ $line }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Cards --}}
                <div class="grid grid-cols-2 gap-3">
                    {{-- Outstanding --}}
                    <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4 text-warning-500" />
                            <span class="text-xs font-medium text-warning-600 dark:text-warning-400">Outstanding</span>
                        </div>
                        <p class="mt-1 text-lg font-bold text-warning-700 dark:text-warning-300">
                            {{ $baseCurrencyCode }} {{ $payables['outstanding'] }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $payables['open_documents'] }} open PO{{ $payables['open_documents'] !== 1 ? 's' : '' }}
                        </p>
                    </div>

                    {{-- Paid --}}
                    <div class="rounded-lg border border-danger-200 bg-danger-50 p-3 dark:border-danger-500/20 dark:bg-danger-500/5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-banknotes" class="h-4 w-4 text-danger-500" />
                            <span class="text-xs font-medium text-danger-600 dark:text-danger-400">Paid</span>
                        </div>
                        <p class="mt-1 text-lg font-bold text-danger-700 dark:text-danger-300">
                            {{ $baseCurrencyCode }} {{ $payables['paid'] }}
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Approved outbound</p>
                    </div>
                </div>

                {{-- Overdue highlight --}}
                @if ($payables['overdue_raw'] > 0)
                    <div class="flex items-center justify-between rounded-lg border border-danger-200 bg-danger-50 px-3 py-2 dark:border-danger-500/20 dark:bg-danger-500/5">
                        <div class="flex items-center gap-2">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-danger-500" />
                            <span class="text-xs font-medium text-danger-700 dark:text-danger-400">Overdue</span>
                        </div>
                        <span class="text-sm font-bold text-danger-700 dark:text-danger-300">
                            {{ $baseCurrencyCode }} {{ $payables['overdue'] }}
                        </span>
                    </div>
                @endif

                {{-- Currency breakdown --}}
                @if (count($payables['by_currency']) > 1)
                    <div class="border-t border-gray-200 pt-3 dark:border-gray-700">
                        <p class="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">By Currency</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($payables['by_currency'] as $item)
                                <span class="rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-gray-700 dark:text-gray-300">
                                    {{ $item['code'] }} {{ $item['amount'] }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>

    {{-- Cashflow Summary --}}
    <div class="mt-4">
        <x-filament::section>
            {{-- Cashflow Conversion Warning --}}
            @if ($cashflow['has_conversion_warning'] ?? false)
                <div class="mb-4 flex items-start gap-2 rounded-lg border border-warning-300 bg-warning-50 px-3 py-2 dark:border-warning-500/30 dark:bg-warning-500/5">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="mt-0.5 h-4 w-4 shrink-0 text-warning-500" />
                    <p class="text-xs font-medium text-warning-700 dark:text-warning-400">
                        {{ __('widgets.expenses.missing_exchange_rate') }} — Cashflow totals may be incomplete.
                    </p>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                {{-- Net Cash Position --}}
                <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Net Cash Position</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">Received − Paid</p>
                    </div>
                    <div class="text-right">
                        <p @class([
                            'text-lg font-bold',
                            'text-success-600 dark:text-success-400' => $cashflow['net_position_raw'] >= 0,
                            'text-danger-600 dark:text-danger-400' => $cashflow['net_position_raw'] < 0,
                        ])>
                            {{ $cashflow['net_position_raw'] < 0 ? '−' : '+' }} {{ $baseCurrencyCode }} {{ $cashflow['net_position'] }}
                        </p>
                        <p @class([
                            'text-xs font-medium',
                            'text-success-500 dark:text-success-400' => $cashflow['net_position_raw'] >= 0,
                            'text-danger-500 dark:text-danger-400' => $cashflow['net_position_raw'] < 0,
                        ])>
                            {{ $cashflow['net_position_label'] }}
                        </p>
                    </div>
                </div>

                {{-- Net Outstanding --}}
                <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Net Outstanding</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">Receivables − Payables</p>
                    </div>
                    <div class="text-right">
                        <p @class([
                            'text-lg font-bold',
                            'text-success-600 dark:text-success-400' => $cashflow['net_outstanding_raw'] >= 0,
                            'text-danger-600 dark:text-danger-400' => $cashflow['net_outstanding_raw'] < 0,
                        ])>
                            {{ $cashflow['net_outstanding_raw'] < 0 ? '−' : '+' }} {{ $baseCurrencyCode }} {{ $cashflow['net_outstanding'] }}
                        </p>
                        <p @class([
                            'text-xs font-medium',
                            'text-success-500 dark:text-success-400' => $cashflow['net_outstanding_raw'] >= 0,
                            'text-danger-500 dark:text-danger-400' => $cashflow['net_outstanding_raw'] < 0,
                        ])>
                            {{ $cashflow['net_outstanding_label'] }}
                        </p>
                    </div>
                </div>

                {{-- Operational Expenses --}}
                <div class="flex items-center justify-between rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <div>
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.financial_stats.operational_expenses') }}</p>
                        <p class="text-xs text-gray-400 dark:text-gray-500">{{ $operationalExpenses['month_label'] }}</p>
                        @if ($operationalExpenses['has_conversion_warning'] ?? false)
                            <p class="mt-1 text-xs font-medium text-warning-600 dark:text-warning-400">
                                ⚠ {{ __('widgets.expenses.missing_exchange_rate') }}
                            </p>
                            @foreach ($operationalExpenses['unconverted'] ?? [] as $line)
                                <p class="text-xs text-warning-500">+ {{ $line }}</p>
                            @endforeach
                        @endif
                    </div>
                    <div class="text-right">
                        <p class="text-lg font-bold text-gray-700 dark:text-gray-300">
                            {{ $baseCurrencyCode }} {{ $operationalExpenses['current_month'] }}
                        </p>
                        @if ($operationalExpenses['change'] != 0)
                            <p @class([
                                'text-xs font-medium',
                                'text-danger-500' => $operationalExpenses['change'] > 0,
                                'text-success-500' => $operationalExpenses['change'] < 0,
                            ])>
                                {{ $operationalExpenses['change'] > 0 ? '+' : '' }}{{ $operationalExpenses['change'] }}% {{ __('widgets.financial_stats.vs_last_month') }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Conversion note --}}
            <p class="mt-3 text-center text-xs text-gray-400 dark:text-gray-500">
                All values converted to {{ $baseCurrencyCode }} using latest approved exchange rates
            </p>
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
