<x-filament-panels::page>
    <div class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-3">
            <div class="col-span-2">
                <label class="block text-sm font-medium mb-1">
                    {{ __('client_deal_breakdown.filters.client') }}
                </label>
                <select wire:model.live="clientId"
                        class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2">
                    <option value="">—</option>
                    @foreach ($this->clientOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block text-sm font-medium mb-1">{{ __('client_deal_breakdown.filters.from') }}</label>
                <input type="date" wire:model.live.debounce.300ms="fromDate"
                       class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">{{ __('client_deal_breakdown.filters.to') }}</label>
                <input type="date" wire:model.live.debounce.300ms="toDate"
                       class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2" />
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">
                    {{ __('client_deal_breakdown.filters.presentation_currency') }}
                </label>
                <select wire:model.live="presentationCurrency"
                        class="fi-input block w-full rounded-lg border border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2">
                    @foreach ($this->currencyOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @php($report = $this->report)

        @if (! $clientId)
            <div class="rounded-lg border border-dashed p-8 text-center text-gray-500">
                {{ __('client_deal_breakdown.select_client_prompt') }}
            </div>
        @elseif ($report && empty($report->deals))
            <div class="rounded-lg border border-dashed p-8 text-center text-gray-500">
                {{ __('client_deal_breakdown.empty_state') }}
            </div>
        @elseif ($report)
            <div data-test="report-container">
                <p class="text-xs text-gray-400">
                    {{ $report->kpi->dealCount }} deals · {{ $report->presentationCurrency }}
                </p>
            </div>
        @endif
    </div>
</x-filament-panels::page>
