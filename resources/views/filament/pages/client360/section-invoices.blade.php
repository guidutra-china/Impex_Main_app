@php use App\Domain\Financial\Enums\PaymentScheduleStatus; @endphp
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
            <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5 text-primary-500" />
            <h2 class="text-sm font-bold text-gray-900 dark:text-white">
                {{ __('client_360.sections.invoices') }}
            </h2>
            <x-filament::badge color="gray" size="xs">{{ $invoices->count() }}</x-filament::badge>
        </div>
        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-500" x-bind:class="open ? '' : '-rotate-90'" />
    </button>
    <div x-show="open" x-collapse>
        @if ($invoices->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-gray-400">{{ __('client_360.empty.invoices') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">{{ __('client_360.columns.reference') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.issue_date') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.valid_until') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('client_360.columns.value') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('client_360.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($invoices as $invoice)
                            @php($hasOverdue = $invoice->paymentScheduleItems->contains(fn ($i) => $i->status === PaymentScheduleStatus::OVERDUE))
                            <tr @class([
                                'group transition-colors',
                                'hover:bg-primary-50/40 dark:hover:bg-primary-500/5' => ! $hasOverdue,
                                'bg-danger-50/40 hover:bg-danger-50 dark:bg-danger-500/5 dark:hover:bg-danger-500/10' => $hasOverdue,
                            ])>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <x-filament::icon icon="heroicon-o-document-text" class="h-4 w-4 text-gray-400 group-hover:text-primary-500" />
                                        <span class="font-mono text-xs font-semibold text-gray-900 dark:text-white">{{ $invoice->reference }}</span>
                                    </div>
                                    @if ($invoice->client_reference)
                                        <p class="ml-6 mt-0.5 text-[10px] font-medium text-gray-500">{{ __('client_360.columns.client_ref') }}: {{ $invoice->client_reference }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    {{ optional($invoice->issue_date)->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    {{ optional($invoice->valid_until)->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    <span class="text-sm font-bold text-gray-900 dark:text-white">
                                        {{ $this->formatMoney((int) $invoice->grand_total, $invoice->currency_code ?? 'USD') }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <x-filament::badge :color="$invoice->status->getColor() ?? 'gray'" size="xs">
                                            {{ $invoice->status->getLabel() }}
                                        </x-filament::badge>
                                        @if ($hasOverdue)
                                            <x-filament::badge color="danger" size="xs" icon="heroicon-m-exclamation-triangle">
                                                {{ __('client_360.financial.overdue') }}
                                            </x-filament::badge>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ $this->invoiceUrl($invoice->id) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700 dark:text-primary-400">
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
