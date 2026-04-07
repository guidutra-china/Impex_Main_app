<div
    x-data="{ open: true }"
    class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
>
    <button
        type="button"
        @click="open = !open"
        class="flex w-full items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700"
    >
        <div class="flex items-center gap-2">
            <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5 text-primary-500" />
            <h2 class="text-sm font-bold text-gray-900 dark:text-white">
                {{ __('client_360.sections.forwarder_freight') }}
            </h2>
            <x-filament::badge color="primary" size="xs">{{ $freightCosts->count() }}</x-filament::badge>
        </div>
        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-500" x-bind:class="open ? '' : '-rotate-90'" />
    </button>

    <div x-show="open" x-collapse class="p-4 space-y-4">
        {{-- Total billed by this forwarder, multi-currency --}}
        <div class="rounded-lg border border-primary-200 bg-primary-50 p-3 dark:border-primary-500/20 dark:bg-primary-500/5">
            <p class="text-xs font-semibold uppercase tracking-wide text-primary-700 dark:text-primary-400">
                {{ __('client_360.financial.total_billed_by_forwarder') }}
            </p>
            <div class="mt-2 space-y-1">
                @forelse ($freightTotals as $currency => $minor)
                    <p class="text-base font-bold text-gray-900 dark:text-white">
                        {{ $this->formatMoney($minor, $currency) }}
                    </p>
                @empty
                    <p class="text-sm text-gray-400">{{ __('client_360.financial.no_pending') }}</p>
                @endforelse
            </div>
        </div>

        {{-- Freight cost lines --}}
        @if ($freightCosts->isNotEmpty())
            <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-3 py-2">{{ __('client_360.columns.date') }}</th>
                            <th class="px-3 py-2">{{ __('client_360.columns.description') }}</th>
                            <th class="px-3 py-2 text-right">{{ __('client_360.columns.value') }}</th>
                            <th class="px-3 py-2">{{ __('client_360.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($freightCosts as $cost)
                            <tr>
                                <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    {{ optional($cost->cost_date)->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-300 truncate max-w-md">
                                    {{ $cost->description ?? '—' }}
                                </td>
                                <td class="px-3 py-2 text-right whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $this->formatMoney((int) $cost->amount, $cost->currency_code ?? 'USD') }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                        {{ $cost->status?->getLabel() ?? $cost->status }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
