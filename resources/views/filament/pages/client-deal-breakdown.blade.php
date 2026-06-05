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
            {{-- KPI cards --}}
            <div class="grid grid-cols-2 md:grid-cols-6 gap-3">
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_received') }}
                    </div>
                    <div class="text-2xl font-bold text-green-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalReceived) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_paid_suppliers') }}
                    </div>
                    <div class="text-2xl font-bold text-red-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalPaidSuppliers) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_paid_shipments') }}
                    </div>
                    <div class="text-2xl font-bold text-orange-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalPaidShipments) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.total_margin') }}
                    </div>
                    <div class="text-2xl font-bold {{ $report->kpi->totalMargin >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalMargin) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.commission_received') }}
                    </div>
                    <div class="text-2xl font-bold text-green-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalCommissionReceived) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-xs uppercase text-gray-500">
                        {{ __('client_deal_breakdown.kpi.commission_paid') }}
                    </div>
                    <div class="text-2xl font-bold text-red-600">
                        {{ \App\Domain\Infrastructure\Support\Money::format($report->kpi->totalCommissionPaid) }}
                        {{ $report->presentationCurrency }}
                    </div>
                </x-filament::section>
            </div>

            {{-- Main table --}}
            <div class="fi-ta-ctn bg-white dark:bg-gray-900 rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs uppercase text-gray-500 bg-gray-50 dark:bg-gray-800/50">
                        <tr>
                            <th class="p-3 w-8"></th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.pi') }}</th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.issue_date') }}</th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.status') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.total') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.received') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.paid_suppliers') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.paid_shipments') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.cash_balance') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.margin') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.commission_received') }}</th>
                            <th class="p-3 text-right">{{ __('client_deal_breakdown.columns.commission_paid') }}</th>
                            <th class="p-3 text-left">{{ __('client_deal_breakdown.columns.currency') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report->deals as $deal)
                            @php($expanded = in_array($deal->pi->id, $expandedDeals, true))
                            <tr class="border-t hover:bg-gray-50 dark:hover:bg-gray-800/40 cursor-pointer"
                                wire:click="toggleDeal({{ $deal->pi->id }})"
                                data-test="deal-row-{{ $deal->pi->id }}">
                                <td class="p-3">{{ $expanded ? '▾' : '▸' }}</td>
                                <td class="p-3">
                                    <a href="{{ $deal->pi->detailUrl }}" target="_blank"
                                       class="font-semibold text-primary-600 hover:underline"
                                       wire:click.stop>
                                        {{ $deal->pi->reference }}
                                    </a>
                                    @if ($deal->pi->clientReference)
                                        <div class="text-xs text-gray-500">
                                            {{ __('client_deal_breakdown.columns.client_reference') }}:
                                            {{ $deal->pi->clientReference }}
                                        </div>
                                    @endif
                                </td>
                                <td class="p-3">{{ $deal->pi->issueDate->format('Y-m-d') }}</td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-800">
                                        {{ $deal->pi->status->getLabel() }}
                                    </span>
                                </td>
                                <td class="p-3 text-right">
                                    {{ \App\Domain\Infrastructure\Support\Money::format($deal->totals->billedToClientPresentation) }}
                                    @if ($deal->totals->billedToClientPresentation !== ($deal->pi->totalPresentation ?? 0))
                                        <div class="text-[10px] text-gray-500">
                                            {{ __('client_deal_breakdown.columns.pi') }}:
                                            {{ \App\Domain\Infrastructure\Support\Money::format($deal->pi->totalPresentation ?? 0) }}
                                        </div>
                                    @endif
                                    @if ($deal->pi->currencyOriginal !== $report->presentationCurrency)
                                        <div class="text-[10px] text-gray-500">
                                            {{ \App\Domain\Infrastructure\Support\Money::format($deal->pi->totalOriginal) }}
                                            {{ $deal->pi->currencyOriginal }}
                                        </div>
                                    @endif
                                </td>
                                <td class="p-3 text-right text-green-600">
                                    {{ \App\Domain\Infrastructure\Support\Money::format($deal->totals->receivedTotalPresentation) }}
                                </td>
                                <td class="p-3 text-right text-red-600">
                                    {{ \App\Domain\Infrastructure\Support\Money::format(collect($deal->purchaseOrders)->sum(fn($p) => $p->paidPresentation ?? 0)) }}
                                </td>
                                <td class="p-3 text-right text-orange-600">
                                    {{ \App\Domain\Infrastructure\Support\Money::format(collect($deal->shipments)->sum(fn($s) => $s->paidPresentation ?? 0)) }}
                                </td>
                                <td class="p-3 text-right font-semibold {{ $deal->totals->cashBalance >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ \App\Domain\Infrastructure\Support\Money::format($deal->totals->cashBalance) }}
                                </td>
                                <td class="p-3 text-right">
                                    <div class="font-semibold {{ $deal->totals->margin >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                                        {{ \App\Domain\Infrastructure\Support\Money::format($deal->totals->margin) }}
                                    </div>
                                    <div class="text-xs text-gray-500">{{ $deal->totals->marginPct }}%</div>
                                </td>
                                <td class="p-3 text-right text-green-600">
                                    @if ($deal->commission->receivedPresentation !== null)
                                        {{ \App\Domain\Infrastructure\Support\Money::format($deal->commission->receivedPresentation) }}
                                        @if (($deal->commission->receivedEmbeddedPresentation ?? 0) > 0)
                                            <div class="text-[10px] text-gray-500">
                                                emb. {{ \App\Domain\Infrastructure\Support\Money::format($deal->commission->receivedEmbeddedPresentation) }}
                                            </div>
                                        @endif
                                    @else
                                        <span class="text-amber-600">⚠</span>
                                    @endif
                                </td>
                                <td class="p-3 text-right">
                                    @if ($deal->commission->paidPresentation !== null)
                                        <div class="text-green-600">
                                            {{ \App\Domain\Infrastructure\Support\Money::format($deal->commission->paidPresentation) }}
                                        </div>
                                        @if (($deal->commission->outstandingPresentation ?? 0) > 0)
                                            <div class="text-[10px] text-amber-600">
                                                {{ __('client_deal_breakdown.commission.outstanding') }}:
                                                {{ \App\Domain\Infrastructure\Support\Money::format($deal->commission->outstandingPresentation) }}
                                            </div>
                                        @elseif (($deal->commission->receivedPresentation ?? 0) > 0)
                                            <div class="text-[10px] text-green-600">✓ {{ __('client_deal_breakdown.commission.settled') }}</div>
                                        @endif
                                    @else
                                        <span class="text-amber-600">⚠</span>
                                    @endif
                                </td>
                                <td class="p-3 text-xs text-gray-500">{{ $deal->pi->currencyOriginal }}</td>
                            </tr>
                            @if ($expanded)
                                <tr class="bg-amber-50 dark:bg-amber-900/10">
                                    <td colspan="13" class="p-4">
                                        <x-client-deal-breakdown.receipts :block="$deal->receipts" :presentationCurrency="$report->presentationCurrency" />
                                        <x-client-deal-breakdown.purchase-orders :rows="$deal->purchaseOrders" :presentationCurrency="$report->presentationCurrency" />
                                        <x-client-deal-breakdown.shipments :rows="$deal->shipments" :expandedShipments="$expandedShipments" :presentationCurrency="$report->presentationCurrency" />
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Client-level Debit Notes (mostly unlinked to a specific PI) --}}
            <x-client-deal-breakdown.debit-notes :rows="$report->debitNotes" :presentationCurrency="$report->presentationCurrency" />

            @if (! empty($report->unconvertedCurrencyPairs))
                <div class="text-xs text-amber-600">
                    ⚠ {{ __('client_deal_breakdown.fx_unavailable_tooltip') }}
                    ({{ implode(', ', $report->unconvertedCurrencyPairs) }})
                </div>
            @endif
        @endif
    </div>
</x-filament-panels::page>
