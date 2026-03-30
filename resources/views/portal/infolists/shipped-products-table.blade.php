@php
    $record = $getRecord();
    $items = $record->items()->with(['proformaInvoiceItem.product', 'proformaInvoiceItem.proformaInvoice'])->get();
@endphp

@if($items->isNotEmpty())
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium">
                <tr>
                    <th class="px-4 py-2.5">Product</th>
                    <th class="px-4 py-2.5">Description</th>
                    <th class="px-4 py-2.5 text-center">Qty</th>
                    <th class="px-4 py-2.5 text-center">Unit</th>
                    <th class="px-4 py-2.5">Proforma Invoice</th>
                    <th class="px-4 py-2.5">Client Ref.</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach($items as $item)
                    <tr class="text-gray-900 dark:text-white">
                        <td class="px-4 py-2.5">{{ $item->proformaInvoiceItem?->product?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ $item->proformaInvoiceItem?->description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center">{{ number_format($item->quantity) }}</td>
                        <td class="px-4 py-2.5 text-center">{{ $item->unit ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            @if($item->proformaInvoiceItem?->proformaInvoice)
                                <span class="inline-flex items-center rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30">
                                    {{ $item->proformaInvoiceItem->proformaInvoice->reference }}
                                </span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400">
                            {{ $item->proformaInvoiceItem?->proformaInvoice?->client_reference ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 italic">No products in this shipment.</p>
@endif
