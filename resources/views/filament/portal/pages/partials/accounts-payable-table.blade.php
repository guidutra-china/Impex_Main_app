<table class="w-full text-sm">
    <thead class="bg-gray-50 dark:bg-gray-800">
        <tr class="text-left">
            <th class="p-2">{{ __('accounts_payable.columns.due_date') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.reference') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.description') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.currency') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.amount') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.paid') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.remaining') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
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
            @endphp
            <tr class="border-t border-gray-100 dark:border-gray-700">
                <td class="p-2">{{ $item->due_date?->format('d/m/Y') ?? '—' }}</td>
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
        @endforeach
    </tbody>
</table>
