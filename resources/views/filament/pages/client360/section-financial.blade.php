@php use App\Domain\Financial\Enums\PaymentDirection; @endphp
<div
    x-data="{ open: true }"
    class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
>
    <button
        type="button"
        @click="open = !open"
        class="flex w-full items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700"
    >
        <h2 class="text-sm font-bold text-gray-900 dark:text-white">
            {{ __('client_360.sections.financial') }}
        </h2>
        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-500" x-bind:class="open ? '' : '-rotate-90'" />
    </button>

    <div x-show="open" x-collapse class="p-4 space-y-4">
        {{-- Receivables vs Payables side-by-side --}}
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            {{-- Receivables --}}
            <div class="rounded-lg border border-success-200 bg-success-50 p-3 dark:border-success-500/20 dark:bg-success-500/5">
                <p class="text-xs font-semibold uppercase tracking-wide text-success-700 dark:text-success-400">
                    {{ __('client_360.financial.receivables') }}
                </p>
                <div class="mt-2 space-y-1">
                    @forelse ($financial->receivablesByCurrency as $currency => $minor)
                        <div class="flex items-baseline justify-between">
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ $this->formatMoney($minor, $currency) }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('client_360.financial.pending_items', ['count' => $financial->receivablesCountByCurrency[$currency] ?? 0]) }}
                                @if (! empty($financial->overdueReceivablesByCurrency[$currency]))
                                    · <span class="text-danger-600 dark:text-danger-400 font-semibold">
                                        {{ $this->formatMoney($financial->overdueReceivablesByCurrency[$currency], $currency) }}
                                        {{ __('client_360.financial.overdue') }}
                                    </span>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('client_360.financial.no_pending') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- Payables --}}
            <div class="rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/5">
                <p class="text-xs font-semibold uppercase tracking-wide text-warning-700 dark:text-warning-400">
                    {{ __('client_360.financial.payables') }}
                </p>
                <div class="mt-2 space-y-1">
                    @forelse ($financial->payablesByCurrency as $currency => $minor)
                        <div class="flex items-baseline justify-between">
                            <span class="text-base font-semibold text-gray-900 dark:text-white">
                                {{ $this->formatMoney($minor, $currency) }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                {{ __('client_360.financial.pending_items', ['count' => $financial->payablesCountByCurrency[$currency] ?? 0]) }}
                                @if (! empty($financial->overduePayablesByCurrency[$currency]))
                                    · <span class="text-danger-600 dark:text-danger-400 font-semibold">
                                        {{ $this->formatMoney($financial->overduePayablesByCurrency[$currency], $currency) }}
                                        {{ __('client_360.financial.overdue') }}
                                    </span>
                                @endif
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-400">{{ __('client_360.financial.no_pending') }}</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Recent transactions --}}
        <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                {{ __('client_360.financial.recent_transactions') }}
            </p>
            @if ($recentPayments->isEmpty())
                <p class="py-4 text-center text-sm text-gray-400">{{ __('client_360.financial.no_pending') }}</p>
            @else
                <div class="overflow-x-auto rounded-lg border border-gray-100 dark:border-gray-700">
                    <table class="min-w-full text-sm">
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                            @foreach ($recentPayments as $payment)
                                @php($isInbound = $payment->direction === PaymentDirection::INBOUND)
                                <tr>
                                    <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        {{ optional($payment->payment_date)->format('Y-m-d') ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <x-filament::icon
                                            :icon="$isInbound ? 'heroicon-o-arrow-down-circle' : 'heroicon-o-arrow-up-circle'"
                                            @class([
                                                'h-4 w-4',
                                                'text-success-500' => $isInbound,
                                                'text-warning-500' => ! $isInbound,
                                            ])
                                        />
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-700 dark:text-gray-300">
                                        {{ $isInbound ? __('client_360.financial.inbound') : __('client_360.financial.outbound') }}
                                    </td>
                                    <td class="px-3 py-2 text-right font-semibold text-gray-900 dark:text-white whitespace-nowrap">
                                        {{ $this->formatMoney((int) $payment->amount, $payment->currency_code ?? 'USD') }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400 truncate max-w-xs">
                                        {{ $payment->number ?? '—' }}@if($payment->reference) <span class="text-gray-400">· {{ $payment->reference }}</span>@endif
                                    </td>
                                    <td class="px-3 py-2 text-right whitespace-nowrap">
                                        <a href="{{ $this->paymentUrl($payment->id) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700 dark:text-primary-400">
                                            {{ __('client_360.actions.view_details') }}
                                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
