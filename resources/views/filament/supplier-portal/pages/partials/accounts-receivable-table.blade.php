<table class="w-full text-sm">
    <thead class="bg-gray-50 dark:bg-gray-800">
        <tr class="text-left">
            <th class="p-2">{{ __('accounts_receivable.columns.due_date') }}</th>
            <th class="p-2">{{ __('accounts_receivable.columns.reference') }}</th>
            <th class="p-2">{{ __('accounts_receivable.columns.description') }}</th>
            <th class="p-2">{{ __('accounts_receivable.columns.currency') }}</th>
            <th class="p-2 text-right">{{ __('accounts_receivable.columns.amount') }}</th>
            <th class="p-2 text-right">{{ __('accounts_receivable.columns.paid') }}</th>
            <th class="p-2 text-right">{{ __('accounts_receivable.columns.remaining') }}</th>
            <th class="p-2">{{ __('accounts_receivable.columns.status') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($items as $item)
            @php
                $payable = $item->payable;
                $isPo = $payable instanceof \App\Domain\PurchaseOrders\Models\PurchaseOrder;
                $isShipment = $payable instanceof \App\Domain\Logistics\Models\Shipment;
                $resourceUrl = match (true) {
                    $isPo => \App\Filament\SupplierPortal\Resources\PurchaseOrderResource::getUrl('view', ['record' => $payable]),
                    $isShipment => \App\Filament\SupplierPortal\Resources\ShipmentResource::getUrl('view', ['record' => $payable]),
                    default => null,
                };
                $supplierInvoice = $isPo ? $payable->supplier_invoice_number : null;
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
                    @if (! empty($supplierInvoice))
                        <div class="text-xs text-gray-500">{{ __('accounts_receivable.columns.supplier_invoice') }}: {{ $supplierInvoice }}</div>
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
