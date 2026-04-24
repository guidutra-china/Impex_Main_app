@props(['rows', 'presentationCurrency'])

<div class="mb-4">
    <div class="text-xs uppercase font-bold text-red-700 dark:text-red-400 mb-1">
        ↑ {{ __('client_deal_breakdown.sections.purchase_orders') }} ({{ count($rows) }})
    </div>
    @if (empty($rows))
        <div class="text-xs text-gray-500 italic">{{ __('client_deal_breakdown.no_pos') }}</div>
    @else
        <table class="w-full text-xs bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 uppercase">
                <tr>
                    <th class="p-2 text-left">PO</th>
                    <th class="p-2 text-left">Supplier</th>
                    <th class="p-2 text-right">Total</th>
                    <th class="p-2 text-right">In {{ $presentationCurrency }}</th>
                    <th class="p-2 text-right">Paid</th>
                    <th class="p-2 text-right">Outstanding</th>
                    <th class="p-2 text-left">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $po)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="p-2">
                            <a href="{{ $po->detailUrl }}" target="_blank" class="text-primary-600 hover:underline"
                               wire:click.stop>{{ $po->reference }}</a>
                        </td>
                        <td class="p-2">{{ $po->supplierName }}</td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($po->totalOriginal) }}
                            <span class="text-gray-500">{{ $po->currencyOriginal }}</span>
                        </td>
                        <td class="p-2 text-right">
                            @if ($po->totalPresentation !== null)
                                {{ \App\Domain\Infrastructure\Support\Money::format($po->totalPresentation) }}
                            @else
                                <span class="text-amber-600">⚠</span>
                            @endif
                        </td>
                        <td class="p-2 text-right text-red-600">
                            {{ \App\Domain\Infrastructure\Support\Money::format($po->paidOriginal) }}
                        </td>
                        <td class="p-2 text-right">
                            {{ \App\Domain\Infrastructure\Support\Money::format($po->outstandingOriginal) }}
                        </td>
                        <td class="p-2">
                            <span class="px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-800">
                                {{ $po->status->getLabel() }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
