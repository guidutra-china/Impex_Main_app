@props(['rows', 'expandedShipments', 'presentationCurrency'])

<div class="mb-2">
    <div class="text-xs uppercase font-bold text-orange-700 dark:text-orange-400 mb-1">
        ↑ {{ __('client_deal_breakdown.sections.shipments') }} ({{ count($rows) }})
    </div>
    @if (empty($rows))
        <div class="text-xs text-gray-500 italic">{{ __('client_deal_breakdown.no_shipments') }}</div>
    @else
        <table class="w-full text-xs bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 uppercase">
                <tr>
                    <th class="p-2 w-6"></th>
                    <th class="p-2 text-left">Shipment</th>
                    <th class="p-2 text-left">Forwarder</th>
                    <th class="p-2 text-right">Total Cost</th>
                    <th class="p-2 text-right">Attribution</th>
                    <th class="p-2 text-right">Attributed ({{ $presentationCurrency }})</th>
                    <th class="p-2 text-right">Paid</th>
                    <th class="p-2 text-right">Outstanding</th>
                    <th class="p-2 text-right">{{ __('client_deal_breakdown.columns.freight_margin') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $s)
                    @php($open = in_array($s->id, $expandedShipments, true))
                    @php($hasCosts = count($s->additionalCosts) > 0)
                    <tr class="border-t border-gray-100 dark:border-gray-800 {{ $hasCosts ? 'cursor-pointer hover:bg-orange-50 dark:hover:bg-orange-900/10' : '' }}"
                        @if ($hasCosts) wire:click="toggleShipment({{ $s->id }})" @endif>
                        <td class="p-2">{{ $hasCosts ? ($open ? '▾' : '▸') : '' }}</td>
                        <td class="p-2">
                            <a href="{{ $s->detailUrl }}" target="_blank"
                               class="text-primary-600 hover:underline" wire:click.stop>
                                {{ $s->reference }}
                            </a>
                            @if ($s->clientReference)
                                <div class="text-xs text-gray-500">{{ $s->clientReference }}</div>
                            @endif
                        </td>
                        <td class="p-2">{{ $s->forwarderName }}</td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($s->totalCostOriginal) }}
                            <span class="text-gray-500">{{ $s->currencyOriginal }}</span>
                            @if ($s->clientChargeOriginal > 0)
                                <div class="text-[10px] text-green-600">
                                    {{ __('client_deal_breakdown.freight.client_charge') }}:
                                    {{ \App\Domain\Infrastructure\Support\Money::format($s->clientChargeOriginal) }}
                                </div>
                            @endif
                        </td>
                        <td class="p-2 text-right">
                            <span class="font-semibold">{{ number_format($s->attributionPct * 100, 1) }}%</span>
                            <div class="text-[10px] text-gray-500">{{ __($s->basis->labelKey()) }}</div>
                        </td>
                        <td class="p-2 text-right">
                            @if ($s->attributedPresentation !== null)
                                {{ \App\Domain\Infrastructure\Support\Money::format($s->attributedPresentation) }}
                            @else
                                <span class="text-amber-600">⚠</span>
                            @endif
                        </td>
                        <td class="p-2 text-right text-red-600">
                            {{ \App\Domain\Infrastructure\Support\Money::format($s->paidOriginal) }}
                            @if ($s->freightReceivedOriginal > 0)
                                <div class="text-[10px] text-green-600">
                                    ↓ {{ __('client_deal_breakdown.freight.received_client') }}:
                                    {{ \App\Domain\Infrastructure\Support\Money::format($s->freightReceivedOriginal) }}
                                </div>
                            @endif
                        </td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($s->outstandingOriginal) }}
                        </td>
                        <td class="p-2 text-right">
                            @php($fm = $s->attributedClientChargeOriginal - $s->attributedOriginal)
                            <span class="font-semibold {{ $fm >= 0 ? 'text-blue-600' : 'text-red-600' }}">
                                {{ \App\Domain\Infrastructure\Support\Money::format($fm) }}
                            </span>
                        </td>
                    </tr>
                    @if ($open && $hasCosts)
                        <tr class="bg-orange-50 dark:bg-orange-900/5">
                            <td></td>
                            <td colspan="8" class="p-2">
                                <div class="text-[10px] uppercase text-gray-500 mb-1">
                                    {{ __('client_deal_breakdown.sections.additional_costs') }}
                                </div>
                                <table class="w-full text-xs">
                                    <thead class="text-gray-500 uppercase text-[10px]">
                                        <tr>
                                            <th class="p-1 text-left">Label</th>
                                            <th class="p-1 text-left">Type</th>
                                            <th class="p-1 text-right">Total</th>
                                            <th class="p-1 text-right">Attributed</th>
                                            <th class="p-1 text-right">In {{ $presentationCurrency }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($s->additionalCosts as $c)
                                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                                <td class="p-1">{{ $c->label }}</td>
                                                <td class="p-1">{{ $c->type?->getLabel() }}</td>
                                                <td class="p-1 text-right">
                                                    {{ \App\Domain\Infrastructure\Support\Money::format($c->totalOriginal) }}
                                                </td>
                                                <td class="p-1 text-right">
                                                    {{ \App\Domain\Infrastructure\Support\Money::format($c->attributedOriginal) }}
                                                </td>
                                                <td class="p-1 text-right">
                                                    @if ($c->attributedPresentation !== null)
                                                        {{ \App\Domain\Infrastructure\Support\Money::format($c->attributedPresentation) }}
                                                    @else
                                                        <span class="text-amber-600">⚠</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif
</div>
