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
            <x-filament::icon icon="heroicon-o-shopping-cart" class="h-5 w-5 text-info-500" />
            <h2 class="text-sm font-bold text-gray-900 dark:text-white">
                {{ __('client_360.sections.supplier_purchase_orders') }}
            </h2>
            <x-filament::badge color="info" size="xs">{{ $purchaseOrders->count() }}</x-filament::badge>
        </div>
        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-500" x-bind:class="open ? '' : '-rotate-90'" />
    </button>
    <div x-show="open" x-collapse>
        @if ($purchaseOrders->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-gray-400">{{ __('client_360.empty.purchase_orders') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">{{ __('client_360.columns.reference') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.issue_date') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.client') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('client_360.columns.po_value') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('client_360.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($purchaseOrders as $po)
                            <tr class="group transition-colors hover:bg-info-50/40 dark:hover:bg-info-500/5">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <x-filament::icon icon="heroicon-o-shopping-cart" class="h-4 w-4 text-gray-400 group-hover:text-info-500" />
                                        <span class="font-mono text-xs font-semibold text-gray-900 dark:text-white">{{ $po->reference }}</span>
                                    </div>
                                    @if ($po->supplier_invoice_number)
                                        <p class="ml-6 mt-0.5 text-[10px] text-gray-400">SI: {{ $po->supplier_invoice_number }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                    {{ optional($po->issue_date)->format('Y-m-d') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-700 dark:text-gray-300">
                                    <p class="truncate max-w-[200px]">{{ $po->proformaInvoice?->company?->name ?? '—' }}</p>
                                    @if ($po->proformaInvoice)
                                        <a href="{{ $this->invoiceUrl($po->proformaInvoice->id) }}" class="font-mono text-[10px] text-primary-600 hover:underline">
                                            {{ $po->proformaInvoice->reference }}
                                        </a>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                    {{ $this->formatMoney((int) $po->total, $po->currency_code ?? 'USD') }}
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <x-filament::badge :color="$po->status->getColor() ?? 'gray'" size="xs">
                                        {{ $po->status->getLabel() }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ $this->purchaseOrderUrl($po->id) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700 dark:text-primary-400">
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
