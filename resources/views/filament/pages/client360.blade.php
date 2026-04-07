<x-filament-panels::page>
    {{-- ================== Client selector ================== --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <label for="client-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            {{ __('client_360.select_client') }}
        </label>
        <select
            id="client-select"
            wire:model.live="clientId"
            class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-white"
        >
            <option value="">— {{ __('client_360.select_client') }} —</option>
            @foreach ($this->clientOptions as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>

        @if ($this->client && $this->branchesCount > 0)
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                {{ __('client_360.consolidated_with_branches', ['count' => $this->branchesCount]) }}
            </p>
        @endif
    </div>

    @if (! $this->client)
        <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-12 text-center dark:border-gray-700 dark:bg-gray-900">
            <x-filament::icon icon="heroicon-o-user-circle" class="mx-auto h-12 w-12 text-gray-400" />
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                {{ __('client_360.no_client_selected') }}
            </p>
        </div>
    @else
        @php($kpis = $this->kpis)
        @php($financial = $this->financialSummary)

        {{-- ================== KPI cards ================== --}}
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
            {{-- Active inquiries --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('client_360.kpis.active_inquiries') }}
                </p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $kpis->activeInquiriesCount }}
                </p>
            </div>

            {{-- Open invoices --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('client_360.kpis.open_invoices') }}
                </p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $kpis->openInvoicesCount }}
                </p>
                <div class="mt-1 space-y-0.5">
                    @forelse ($kpis->openInvoicesValueByCurrency as $currency => $minor)
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $this->formatMoney($minor, $currency) }}
                        </p>
                    @empty
                        <p class="text-xs text-gray-400">—</p>
                    @endforelse
                </div>
            </div>

            {{-- POs in progress --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('client_360.kpis.pos_in_progress') }}
                </p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $kpis->purchaseOrdersInProgressCount }}
                </p>
            </div>

            {{-- Shipments in transit --}}
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('client_360.kpis.shipments_in_transit') }}
                </p>
                <p class="mt-2 text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $kpis->shipmentsInTransitCount }}
                </p>
            </div>

            {{-- Pending receivables (multi-currency) --}}
            <div @class([
                'rounded-xl border p-4 shadow-sm',
                'border-warning-300 bg-warning-50 dark:border-warning-500/30 dark:bg-warning-500/10'
                    => ! empty($financial->overdueReceivablesByCurrency),
                'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-800'
                    => empty($financial->overdueReceivablesByCurrency),
            ])>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('client_360.kpis.pending_receivables') }}
                </p>
                <div class="mt-2 space-y-0.5">
                    @forelse ($kpis->pendingReceivablesByCurrency as $currency => $minor)
                        <p class="text-sm font-semibold text-gray-900 dark:text-white">
                            {{ $this->formatMoney($minor, $currency) }}
                        </p>
                    @empty
                        <p class="text-sm text-gray-400">—</p>
                    @endforelse
                </div>
            </div>

            {{-- Alerts --}}
            <div @class([
                'rounded-xl border p-4 shadow-sm',
                'border-danger-300 bg-danger-50 dark:border-danger-500/30 dark:bg-danger-500/10'
                    => $kpis->alertsCount > 0,
                'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/5'
                    => $kpis->alertsCount === 0,
            ])>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    {{ __('client_360.kpis.alerts') }}
                </p>
                <p @class([
                    'mt-2 text-2xl font-bold',
                    'text-danger-600 dark:text-danger-400' => $kpis->alertsCount > 0,
                    'text-success-600 dark:text-success-400' => $kpis->alertsCount === 0,
                ])>
                    {{ $kpis->alertsCount }}
                </p>
                @if ($kpis->alertsCount === 0)
                    <p class="text-xs text-success-600 dark:text-success-400">{{ __('client_360.kpis.no_alerts') }}</p>
                @endif
            </div>
        </div>

        {{-- ================== Timeline (collapsible, starts closed) ================== --}}
        <div
            x-data="{ open: false }"
            class="rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800"
        >
            <button
                type="button"
                @click="open = !open"
                class="flex w-full items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700"
            >
                <h2 class="text-sm font-bold text-gray-900 dark:text-white">
                    {{ __('client_360.sections.timeline') }}
                    <span class="ml-2 inline-flex items-center justify-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                        {{ $this->timeline->count() }}
                    </span>
                </h2>
                <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-500" x-bind:class="open ? '' : '-rotate-90'" />
            </button>
            <div x-show="open" x-collapse>
                <div class="max-h-96 overflow-y-auto p-4">
                @forelse ($this->timeline as $event)
                    <div class="flex gap-3 py-2 border-b border-gray-100 last:border-b-0 dark:border-gray-700">
                        <div class="flex-shrink-0">
                            <x-filament::icon
                                :icon="match($event->type) {
                                    'inquiry_created' => 'heroicon-o-inbox-arrow-down',
                                    'invoice_issued' => 'heroicon-o-document-text',
                                    'invoice_confirmed' => 'heroicon-o-check-badge',
                                    'po_issued' => 'heroicon-o-shopping-cart',
                                    'shipment_departed' => 'heroicon-o-truck',
                                    'shipment_arrived' => 'heroicon-o-flag',
                                    'payment_received' => 'heroicon-o-arrow-down-circle',
                                    'payment_sent' => 'heroicon-o-arrow-up-circle',
                                    default => 'heroicon-o-clock',
                                }"
                                @class([
                                    'h-5 w-5',
                                    'text-info-500' => in_array($event->type, ['inquiry_created', 'invoice_issued']),
                                    'text-primary-500' => in_array($event->type, ['invoice_confirmed', 'po_issued']),
                                    'text-success-500' => in_array($event->type, ['payment_received', 'shipment_arrived']),
                                    'text-warning-500' => in_array($event->type, ['payment_sent', 'shipment_departed']),
                                ])
                            />
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-baseline justify-between gap-2">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                    {{ $event->title }}
                                </p>
                                <span class="text-xs text-gray-500 dark:text-gray-400 flex-shrink-0">
                                    {{ $event->occurredAt->format('Y-m-d') }}
                                </span>
                            </div>
                            @if ($event->subtitle)
                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ $event->subtitle }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-gray-400">{{ __('client_360.timeline.no_events') }}</p>
                @endforelse
                </div>
            </div>
        </div>

        {{-- ================== Inquiries section ================== --}}
        @include('filament.pages.client360.section-inquiries', ['inquiries' => $this->inquiries])

        {{-- ================== Invoices section ================== --}}
        @include('filament.pages.client360.section-invoices', ['invoices' => $this->invoices])

        {{-- ================== Purchase Orders section ================== --}}
        @include('filament.pages.client360.section-purchase-orders', ['purchaseOrders' => $this->purchaseOrders])

        {{-- ================== Shipments section ================== --}}
        @include('filament.pages.client360.section-shipments', ['shipments' => $this->shipments])

        {{-- ================== Financial section ================== --}}
        @include('filament.pages.client360.section-financial', [
            'financial' => $financial,
            'recentPayments' => $this->recentPayments,
        ])
    @endif
</x-filament-panels::page>
