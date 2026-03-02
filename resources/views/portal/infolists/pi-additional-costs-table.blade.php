@php
    $record = $getRecord();
    $costs = $record->clientBillableCosts;
@endphp

@if($costs->isNotEmpty())
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium">
                <tr>
                    <th class="px-4 py-2.5">Type</th>
                    <th class="px-4 py-2.5">Description</th>
                    <th class="px-4 py-2.5 text-right">Amount</th>
                    <th class="px-4 py-2.5 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach($costs as $cost)
                    <tr class="text-gray-900 dark:text-white">
                        <td class="px-4 py-2.5">
                            <span class="inline-flex items-center rounded-md bg-gray-50 px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20">
                                {{ $cost->cost_type?->getLabel() ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-2.5">{{ $cost->description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-bold">{{ \App\Domain\Infrastructure\Support\Money::format($cost->amount_in_document_currency, 2) }}</td>
                        <td class="px-4 py-2.5 text-center">
                            @php
                                $statusColor = match($cost->status?->value ?? '') {
                                    'paid' => 'green',
                                    'pending' => 'yellow',
                                    'cancelled' => 'red',
                                    default => 'gray',
                                };
                            @endphp
                            <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset
                                {{ $statusColor === 'green' ? 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-500/10 dark:text-green-400 dark:ring-green-500/20' : '' }}
                                {{ $statusColor === 'yellow' ? 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-500/10 dark:text-yellow-400 dark:ring-yellow-500/20' : '' }}
                                {{ $statusColor === 'red' ? 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20' : '' }}
                                {{ $statusColor === 'gray' ? 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20' : '' }}
                            ">
                                {{ $cost->status?->getLabel() ?? '—' }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
