@php
    $record = $getRecord();
    $items = $record->items()->with('product')->get();
    $showFinancial = auth()->user()?->can('portal:view-financial-summary');
    $currency = $record->currency_code ?? 'USD';
@endphp

@if($items->isNotEmpty())
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium">
                <tr>
                    <th class="px-4 py-2.5">Product</th>
                    <th class="px-4 py-2.5">Description</th>
                    <th class="px-4 py-2.5 text-center">Quantity</th>
                    <th class="px-4 py-2.5 text-center">Unit</th>
                    @if($showFinancial)
                        <th class="px-4 py-2.5 text-right">Unit price</th>
                        <th class="px-4 py-2.5 text-right">Line total</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach($items as $item)
                    <tr class="text-gray-900 dark:text-white">
                        <td class="px-4 py-2.5">{{ $item->product?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ $item->description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center">{{ number_format($item->quantity) }}</td>
                        <td class="px-4 py-2.5 text-center">{{ $item->unit ?? 'pcs' }}</td>
                        @if($showFinancial)
                            <td class="px-4 py-2.5 text-right">{{ \App\Domain\Infrastructure\Support\Money::format($item->unit_price) }}</td>
                            <td class="px-4 py-2.5 text-right font-bold">{{ \App\Domain\Infrastructure\Support\Money::format($item->line_total, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            @if($showFinancial)
                <tfoot class="bg-gray-50 dark:bg-white/5 font-bold text-gray-900 dark:text-white">
                    <tr>
                        <td colspan="4" class="px-4 py-2.5 text-right">Subtotal</td>
                        <td class="px-4 py-2.5"></td>
                        <td class="px-4 py-2.5 text-right">{{ $currency }} {{ \App\Domain\Infrastructure\Support\Money::format($record->total, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 italic">No items in this proforma invoice.</p>
@endif
