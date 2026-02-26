@php
    use App\Domain\Infrastructure\Support\Money;
@endphp

<div class="space-y-4">
    {{-- Summary --}}
    <div class="grid grid-cols-4 gap-4 rounded-lg bg-gray-50 dark:bg-white/5 p-4">
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Schedule Total</p>
            <p class="text-base font-bold text-gray-950 dark:text-white">{{ $record->currency_code }} {{ Money::format($record->schedule_total) }}</p>
        </div>
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Paid</p>
            <p class="text-base font-bold text-success-600 dark:text-success-400">{{ $record->currency_code }} {{ Money::format($record->schedule_paid_total) }}</p>
        </div>
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Remaining</p>
            <p class="text-base font-bold {{ $record->schedule_remaining > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                {{ $record->currency_code }} {{ Money::format($record->schedule_remaining) }}
            </p>
        </div>
        <div>
            <p class="text-sm text-gray-500 dark:text-gray-400">Progress</p>
            @php $progress = $record->payment_progress; @endphp
            <div class="flex items-center gap-2">
                <div class="h-2 flex-1 rounded-full bg-gray-200 dark:bg-gray-700">
                    <div class="h-2 rounded-full {{ $progress >= 100 ? 'bg-success-500' : ($progress >= 50 ? 'bg-warning-500' : 'bg-danger-500') }}"
                         style="width: {{ min($progress, 100) }}%"></div>
                </div>
                <span class="text-sm font-semibold {{ $progress >= 100 ? 'text-success-600' : ($progress >= 50 ? 'text-warning-600' : 'text-danger-600') }}">
                    {{ $progress }}%
                </span>
            </div>
        </div>
    </div>

    {{-- Schedule Items Table --}}
    @if($record->paymentScheduleItems->count() > 0)
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-gray-50 dark:bg-white/5">
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Description</th>
                        <th class="px-4 py-2.5 text-center font-medium text-gray-500 dark:text-gray-400">%</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500 dark:text-gray-400">Amount</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500 dark:text-gray-400">Paid</th>
                        <th class="px-4 py-2.5 text-right font-medium text-gray-500 dark:text-gray-400">Remaining</th>
                        <th class="px-4 py-2.5 text-left font-medium text-gray-500 dark:text-gray-400">Due Date</th>
                        <th class="px-4 py-2.5 text-center font-medium text-gray-500 dark:text-gray-400">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    @foreach($record->paymentScheduleItems->sortBy('sort_order') as $item)
                        <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="px-4 py-2.5 font-medium text-gray-950 dark:text-white {{ $item->is_credit ? 'text-success-600 dark:text-success-400' : '' }}">
                                {{ $item->label }}
                                @if($item->is_credit)
                                    <span class="ml-1 text-xs text-success-600 dark:text-success-400">(CREDIT)</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-center text-gray-600 dark:text-gray-300">{{ $item->percentage }}%</td>
                            <td class="px-4 py-2.5 text-right {{ $item->is_credit ? 'text-success-600 dark:text-success-400' : 'text-gray-950 dark:text-white' }}">
                                {{ $item->is_credit ? '-' : '' }}{{ Money::format($item->amount) }}
                            </td>
                            <td class="px-4 py-2.5 text-right text-success-600 dark:text-success-400">{{ Money::format($item->paid_amount) }}</td>
                            <td class="px-4 py-2.5 text-right {{ $item->remaining_amount > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-success-600 dark:text-success-400' }}">
                                {{ Money::format($item->remaining_amount) }}
                            </td>
                            <td class="px-4 py-2.5 text-gray-600 dark:text-gray-300">{{ $item->due_date?->format('d/m/Y') ?? 'TBD' }}</td>
                            <td class="px-4 py-2.5 text-center">
                                @php
                                    $statusColor = match($item->status->value) {
                                        'paid' => 'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/20',
                                        'waived' => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20',
                                        'overdue' => 'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/20',
                                        'due' => 'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/20',
                                        default => 'bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/20',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $statusColor }}">
                                    {{ $item->status->getLabel() }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        <div class="rounded-lg border border-gray-200 dark:border-white/10 p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No payment schedule generated yet.
        </div>
    @endif
</div>
