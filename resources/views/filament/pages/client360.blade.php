<x-filament-panels::page>
    {{-- ================== Client selector (single-input searchable combobox) ================== --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <label for="client-360-search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            {{ __('client_360.select_client') }}
        </label>

        <div
            x-data="{
                open: false,
                query: @js($this->client?->name ?? ''),
                options: @js($this->clientOptions),
                get filtered() {
                    const entries = Object.entries(this.options);
                    if (! this.query.trim()) return entries;
                    const q = this.query.toLowerCase();
                    return entries.filter(([id, name]) => name.toLowerCase().includes(q));
                },
                pick(id, name) {
                    this.query = name;
                    this.open = false;
                    $wire.set('clientId', id);
                },
                clear() {
                    this.query = '';
                    this.open = true;
                    $wire.set('clientId', null);
                    this.$nextTick(() => this.$refs.input?.focus());
                },
            }"
            @click.outside="open = false"
            @keydown.escape.window="open = false"
            class="relative"
        >
            {{-- Single fake input made of flex children: lupa + native input + buttons --}}
            <div
                class="flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 shadow-sm focus-within:border-primary-500 focus-within:ring-1 focus-within:ring-primary-500 dark:border-gray-600 dark:bg-gray-900"
                style="height: 2.5rem;"
                @click="$refs.input.focus()"
            >
                {{-- Magnifying glass (inline SVG to avoid component CSS quirks) --}}
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4 flex-shrink-0 text-gray-400">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 5.196a7.5 7.5 0 0 0 10.607 10.607Z" />
                </svg>

                <input
                    id="client-360-search"
                    type="text"
                    x-ref="input"
                    x-model="query"
                    @focus="open = true"
                    @input="open = true"
                    placeholder="{{ __('client_360.search_placeholder') }}"
                    autocomplete="off"
                    class="min-w-0 flex-1 border-0 bg-transparent p-0 text-sm text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-white dark:placeholder-gray-500"
                />

                {{-- Clear button --}}
                <button
                    type="button"
                    x-show="query"
                    @click.stop="clear()"
                    class="flex-shrink-0 rounded p-0.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-700"
                    title="Clear"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4">
                        <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" />
                    </svg>
                </button>

                {{-- Chevron toggle --}}
                <button
                    type="button"
                    @click.stop="open = ! open; if (open) $refs.input.focus();"
                    class="flex-shrink-0 rounded p-0.5 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4 transition-transform" x-bind:class="open ? 'rotate-180' : ''">
                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>
            </div>

            {{-- Dropdown panel --}}
            <div
                x-show="open"
                x-transition:enter="transition ease-out duration-100"
                x-transition:enter-start="opacity-0 scale-95"
                x-transition:enter-end="opacity-100 scale-100"
                x-cloak
                class="absolute z-50 mt-1 w-full rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800"
            >
                <ul class="max-h-72 overflow-y-auto py-1 text-sm">
                    <template x-for="[id, name] in filtered" :key="id">
                        <li>
                            <button
                                type="button"
                                @click="pick(id, name)"
                                class="flex w-full items-center px-3 py-2 text-left text-gray-700 hover:bg-primary-50 hover:text-primary-700 dark:text-gray-200 dark:hover:bg-primary-500/10 dark:hover:text-primary-300"
                            >
                                <span x-text="name" class="truncate"></span>
                            </button>
                        </li>
                    </template>
                    <li x-show="filtered.length === 0" class="px-3 py-4 text-center text-xs text-gray-400">
                        {{ __('client_360.no_results') }}
                    </li>
                </ul>
            </div>
        </div>

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
