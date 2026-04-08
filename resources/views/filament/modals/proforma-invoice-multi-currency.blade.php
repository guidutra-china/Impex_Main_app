@php
    use App\Domain\Infrastructure\Support\Money;
@endphp
<div class="space-y-3">
    <table class="w-full text-sm">
        <thead class="text-left text-gray-500 dark:text-gray-400">
            <tr>
                <th class="py-1">{{ __('forms.labels.product') }}</th>
                <th class="py-1 text-right">{{ __('forms.labels.qty') }}</th>
                <th class="py-1 text-right">{{ __('forms.labels.unit_cost_internal') }}</th>
                <th class="py-1 text-right">{{ __('forms.labels.exchange_rate') }}</th>
                <th class="py-1 text-right">{{ __('forms.labels.cost_in_pi_currency') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr class="border-t border-gray-100 dark:border-gray-700">
                    <td class="py-1">{{ $item->product?->name ?? $item->description }}</td>
                    <td class="py-1 text-right">{{ $item->quantity }}</td>
                    <td class="py-1 text-right">
                        {{ $item->cost_currency_code }}
                        {{ Money::format($item->unit_cost ?? 0, 4) }}
                    </td>
                    <td class="py-1 text-right font-mono">
                        {{ number_format((float) $item->cost_exchange_rate, 6) }}
                    </td>
                    <td class="py-1 text-right">
                        {{ $piCurrency }}
                        {{ Money::format($item->unit_cost_in_document_currency ?? 0, 4) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
