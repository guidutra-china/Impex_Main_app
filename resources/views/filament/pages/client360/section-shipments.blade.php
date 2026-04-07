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
            <x-filament::icon icon="heroicon-o-truck" class="h-5 w-5 text-info-500" />
            <h2 class="text-sm font-bold text-gray-900 dark:text-white">
                {{ __('client_360.sections.shipments') }}
            </h2>
            <x-filament::badge color="gray" size="xs">{{ $shipments->count() }}</x-filament::badge>
        </div>
        <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 text-gray-500" x-bind:class="open ? '' : '-rotate-90'" />
    </button>
    <div x-show="open" x-collapse>
        @if ($shipments->isEmpty())
            <p class="px-4 py-8 text-center text-sm text-gray-400">{{ __('client_360.empty.shipments') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                        <tr class="text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            <th class="px-4 py-3">{{ __('client_360.columns.reference') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.mode') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.booking_bl') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.origin_destination') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.etd_eta') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('client_360.columns.freight') }}</th>
                            <th class="px-4 py-3">{{ __('client_360.columns.status') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('client_360.columns.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($shipments as $shipment)
                            @php($freight = $this->shipmentFreightByCurrency($shipment))
                            <tr class="group transition-colors hover:bg-info-50/40 dark:hover:bg-info-500/5">
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <div class="flex items-center gap-2">
                                        <x-filament::icon icon="heroicon-o-truck" class="h-4 w-4 text-gray-400 group-hover:text-info-500" />
                                        <span class="font-mono text-xs font-semibold text-gray-900 dark:text-white">{{ $shipment->reference }}</span>
                                    </div>
                                    @if ($shipment->client_reference)
                                        <p class="ml-6 mt-0.5 text-[10px] font-medium text-gray-500">{{ __('client_360.columns.client_ref') }}: {{ $shipment->client_reference }}</p>
                                    @endif
                                    @if ($shipment->forwarderCompany)
                                        <p class="ml-6 mt-0.5 text-[10px] text-gray-400 truncate max-w-[180px]">{{ $shipment->forwarderCompany->name }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @php($mode = $shipment->transport_mode instanceof \BackedEnum ? $shipment->transport_mode->value : $shipment->transport_mode)
                                    @if ($mode)
                                        <x-filament::badge color="gray" size="xs">{{ $mode }}</x-filament::badge>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="font-mono text-xs text-gray-700 dark:text-gray-300">
                                        {{ $shipment->booking_number ?? $shipment->bl_number ?? '—' }}
                                    </span>
                                    @if ($shipment->container_number)
                                        <p class="font-mono text-[10px] text-gray-400">{{ $shipment->container_number }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-1 text-xs text-gray-700 dark:text-gray-300">
                                        <span class="truncate max-w-[100px]">{{ $shipment->origin_port ?? '—' }}</span>
                                        <x-filament::icon icon="heroicon-m-arrow-long-right" class="h-3 w-3 text-gray-400 flex-shrink-0" />
                                        <span class="truncate max-w-[100px] font-medium">{{ $shipment->destination_port ?? '—' }}</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400">
                                        ETD: {{ optional($shipment->etd)->format('Y-m-d') ?? '—' }}
                                    </p>
                                    <p class="text-[10px] text-gray-500 dark:text-gray-400">
                                        ETA: {{ optional($shipment->eta)->format('Y-m-d') ?? '—' }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">
                                    @if (! empty($freight))
                                        @foreach ($freight as $currency => $minor)
                                            <p class="text-xs font-semibold text-gray-900 dark:text-white">
                                                {{ $this->formatMoney($minor, $currency) }}
                                            </p>
                                        @endforeach
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <x-filament::badge :color="$shipment->status->getColor() ?? 'gray'" size="xs">
                                        {{ $shipment->status->getLabel() }}
                                    </x-filament::badge>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <a href="{{ $this->shipmentUrl($shipment->id) }}" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:text-primary-700 dark:text-primary-400">
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
