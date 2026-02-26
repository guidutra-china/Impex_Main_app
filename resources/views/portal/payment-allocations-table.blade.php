@php
    use App\Domain\Infrastructure\Support\Money;

    $allocations = $record->allocations()
        ->with(['paymentScheduleItem.payable'])
        ->get();
@endphp

<div class="space-y-4">
    @if($allocations->count() > 0)
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Document</th>
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Schedule Item</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500 dark:text-gray-400">Allocated Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach($allocations as $allocation)
                        @php
                            $scheduleItem = $allocation->paymentScheduleItem;
                            $payable = $scheduleItem?->payable;
                            $documentRef = $payable?->reference ?? 'N/A';
                            $documentType = match(class_basename($payable ?? '')) {
                                'ProformaInvoice' => 'PI',
                                'Shipment' => 'Shipment',
                                'PurchaseOrder' => 'PO',
                                default => class_basename($payable ?? 'Unknown'),
                            };
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center gap-1.5">
                                    <span class="inline-flex items-center rounded-md bg-primary-50 px-1.5 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20 dark:bg-primary-400/10 dark:text-primary-400 dark:ring-primary-400/20">
                                        {{ $documentType }}
                                    </span>
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $documentRef }}</span>
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">
                                {{ $scheduleItem?->label ?? '—' }}
                            </td>
                            <td class="px-4 py-2.5 text-right font-medium text-gray-950 dark:text-white">
                                {{ $record->currency_code }} {{ Money::format($allocation->allocated_amount_in_document_currency) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <td colspan="2" class="px-4 py-2.5 text-right font-medium text-gray-500 dark:text-gray-400">Total Allocated</td>
                        <td class="px-4 py-2.5 text-right font-bold text-gray-950 dark:text-white">
                            {{ $record->currency_code }} {{ Money::format($allocations->sum('allocated_amount_in_document_currency')) }}
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 dark:border-white/10 p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No allocations found for this payment.
        </div>
    @endif
</div>
