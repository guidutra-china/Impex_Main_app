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
            <tr class="border-t border-gray-100 dark:border-gray-700">
                <td class="p-2">{{ $item->due_date?->format('d/m/Y') }}</td>
                <td class="p-2">
                    @if ($item->payable)
                        <a href="{{ \App\Filament\Portal\Resources\ProformaInvoiceResource::getUrl('view', ['record' => $item->payable]) }}" class="text-primary-600 underline">
                            {{ $item->payable->reference ?? '—' }}
                        </a>
                    @else
                        —
                    @endif
                </td>
                <td class="p-2">{{ $item->label }}</td>
                <td class="p-2">{{ $item->currency_code }}</td>
                <td class="p-2 text-right">{{ number_format($item->amount / 100, 2) }}</td>
                <td class="p-2 text-right">{{ number_format($item->paid_amount / 100, 2) }}</td>
                <td class="p-2 text-right font-medium">{{ number_format($item->remaining_amount / 100, 2) }}</td>
                <td class="p-2">
                    <span class="inline-flex px-2 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-800">
                        {{ $item->status->getLabel() }}
                    </span>
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
