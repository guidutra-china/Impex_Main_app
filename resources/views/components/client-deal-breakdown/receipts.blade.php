@props(['block', 'presentationCurrency'])

<div class="mb-4">
    <div class="text-xs uppercase font-bold text-green-700 dark:text-green-400 mb-1">
        ↓ {{ __('client_deal_breakdown.sections.receipts') }}
        —
        {{ \App\Domain\Infrastructure\Support\Money::format($block->paidOriginal) }}
        / {{ \App\Domain\Infrastructure\Support\Money::format($block->paidOriginal + $block->outstandingOriginal) }}
        ({{ $block->percentPaid }}%)
    </div>
    @if (empty($block->items))
        <div class="text-xs text-gray-500 italic">{{ __('client_deal_breakdown.no_receipts') }}</div>
    @else
        <table class="w-full text-xs bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-800">
            <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 uppercase">
                <tr>
                    <th class="p-2 text-left">Date</th>
                    <th class="p-2 text-left">Ref</th>
                    <th class="p-2 text-left">Stage</th>
                    <th class="p-2 text-right">Amount</th>
                    <th class="p-2 text-right">In {{ $presentationCurrency }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($block->items as $r)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="p-2">{{ $r->paymentDate->format('Y-m-d') }}</td>
                        <td class="p-2">{{ $r->paymentReference }}</td>
                        <td class="p-2">{{ $r->stageLabel }}</td>
                        <td class="p-2 text-right text-green-600">
                            {{ \App\Domain\Infrastructure\Support\Money::format($r->amountOriginal) }}
                        </td>
                        <td class="p-2 text-right">
                            @if ($r->amountPresentation !== null)
                                {{ \App\Domain\Infrastructure\Support\Money::format($r->amountPresentation) }}
                            @else
                                <span class="text-amber-600">⚠</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
