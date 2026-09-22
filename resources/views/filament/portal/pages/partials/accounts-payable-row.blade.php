@php
    $payable = $item->payable;
    $isShipment = $payable instanceof \App\Domain\Logistics\Models\Shipment;
    $isPi = $payable instanceof \App\Domain\ProformaInvoices\Models\ProformaInvoice;
    $resourceUrl = match (true) {
        $isPi => \App\Filament\Portal\Resources\ProformaInvoiceResource::getUrl('view', ['record' => $payable]),
        $isShipment => \App\Filament\Portal\Resources\ShipmentResource::getUrl('view', ['record' => $payable]),
        default => null,
    };
    $clientRef = $payable?->client_reference;
    $nested = $nested ?? false;
@endphp
<tr
    @if ($nested) x-show="open" x-cloak @endif
    @class([
        'border-t border-gray-100 dark:border-gray-700',
        'bg-gray-50/60 dark:bg-white/5' => $nested,
    ])
>
    <td @class(['p-2', 'pl-8' => $nested])>{{ $item->due_date?->format('d/m/Y') ?? '—' }}</td>
    <td class="p-2">
        @if ($resourceUrl)
            <a href="{{ $resourceUrl }}" class="text-primary-600 underline">
                {{ $payable->reference ?? $payable->number ?? '—' }}
            </a>
        @else
            —
        @endif
    </td>
    <td class="p-2">
        <div>{{ $item->label }}</div>
        @if (! empty($clientRef))
            <div class="text-xs text-gray-500">{{ __('accounts_payable.columns.client_reference') }}: {{ $clientRef }}</div>
        @endif
    </td>
    <td class="p-2">{{ $item->currency_code }}</td>
    <td class="p-2 text-right">{{ number_format($item->amount / 10000, 2) }}</td>
    <td class="p-2 text-right">{{ number_format($item->paid_amount / 10000, 2) }}</td>
    <td class="p-2 text-right font-medium">{{ number_format($item->remaining_amount / 10000, 2) }}</td>
    <td class="p-2">
        <span class="inline-flex px-2 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-800">
            {{ $item->status->getLabel() }}
        </span>
    </td>
</tr>
