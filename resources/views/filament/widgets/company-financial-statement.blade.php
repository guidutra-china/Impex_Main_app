<x-filament-widgets::widget>
    <div class="flex flex-col gap-6">
        @foreach ($sections as $section)
            <x-filament::section
                :heading="$section['title']"
                :icon="$section['icon']"
                collapsible
            >
                {{-- Summary Stats Grid --}}
                <div class="grid grid-cols-2 gap-3 mb-6 sm:grid-cols-4">
                    {{-- Total Card --}}
                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-200 dark:bg-white/10">
                                <x-filament::icon icon="heroicon-o-document-currency-dollar" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</p>
                                <p class="truncate text-lg font-bold text-gray-900 dark:text-white">{{ $section['summary']['total_invoiced'] }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Paid Card --}}
                    <div class="rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-500/20 dark:bg-success-500/5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-success-200 dark:bg-success-500/20">
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600 dark:text-success-400" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-success-600 dark:text-success-400">Paid</p>
                                <p class="truncate text-lg font-bold text-success-700 dark:text-success-300">{{ $section['summary']['total_paid'] }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Remaining Card --}}
                    <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-500/20 dark:bg-warning-500/5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-warning-200 dark:bg-warning-500/20">
                                <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-warning-600 dark:text-warning-400" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-warning-600 dark:text-warning-400">Remaining</p>
                                <p class="truncate text-lg font-bold text-warning-700 dark:text-warning-300">{{ $section['summary']['total_remaining'] }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Overdue Card --}}
                    @php $hasOverdue = !empty($section['summary']['total_overdue']); @endphp
                    <div @class([
                        'rounded-xl border p-4',
                        'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/5' => $hasOverdue,
                        'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => !$hasOverdue,
                    ])>
                        <div class="flex items-center gap-3">
                            <div @class([
                                'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                                'bg-danger-200 dark:bg-danger-500/20' => $hasOverdue,
                                'bg-gray-200 dark:bg-white/10' => !$hasOverdue,
                            ])>
                                @if ($hasOverdue)
                                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-danger-600 dark:text-danger-400" />
                                @else
                                    <x-filament::icon icon="heroicon-o-check" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                                @endif
                            </div>
                            <div class="min-w-0">
                                <p @class([
                                    'text-[0.65rem] font-semibold uppercase tracking-wide',
                                    'text-danger-600 dark:text-danger-400' => $hasOverdue,
                                    'text-gray-400 dark:text-gray-500' => !$hasOverdue,
                                ])>Overdue</p>
                                <p @class([
                                    'truncate text-lg font-bold',
                                    'text-danger-700 dark:text-danger-300' => $hasOverdue,
                                    'text-gray-400 dark:text-gray-500' => !$hasOverdue,
                                ])>
                                    {{ $section['summary']['total_overdue'] ?? "\u{2014}" }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Documents Table --}}
                @if (count($section['rows']) > 0)
                    <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                    <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Reference</th>
                                    <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Date</th>
                                    <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</th>
                                    <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Paid</th>
                                    <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remaining</th>
                                    <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($section['rows'] as $index => $row)
                                    <tr @class([
                                        'border-b border-gray-100 dark:border-white/5',
                                        'bg-gray-50/50 dark:bg-white/[0.02]' => $index % 2 === 1,
                                    ])>
                                        <td class="whitespace-nowrap px-4 py-2.5">
                                            <a href="{{ $row['url'] }}" class="font-semibold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400">
                                                {{ $row['reference'] }}
                                            </a>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-gray-500 dark:text-gray-400">{{ $row['date'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-2.5">
                                            <x-filament::badge :color="$row['status']->getColor()" size="sm">
                                                {{ $row['status']->getLabel() }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium text-gray-900 dark:text-white">
                                            <span class="text-gray-400 dark:text-gray-500">{{ $row['currency'] }}</span> {{ $row['total'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium text-success-600 dark:text-success-400">
                                            <span class="text-success-400 dark:text-success-600">{{ $row['currency'] }}</span> {{ $row['paid'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium text-warning-600 dark:text-warning-400">
                                            <span class="text-warning-400 dark:text-warning-600">{{ $row['currency'] }}</span> {{ $row['remaining'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono">
                                            @if ($row['overdue'])
                                                <span class="font-bold text-danger-600 dark:text-danger-400">
                                                    <span class="text-danger-400 dark:text-danger-600">{{ $row['currency'] }}</span> {{ $row['overdue'] }}
                                                </span>
                                            @else
                                                <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                    <td colspan="3" class="px-4 py-3 text-sm font-bold text-gray-700 dark:text-gray-300">
                                        Total ({{ count($section['rows']) }} {{ count($section['rows']) === 1 ? 'record' : 'records' }})
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-gray-900 dark:text-white">
                                        {{ $section['summary']['total_invoiced'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-success-600 dark:text-success-400">
                                        {{ $section['summary']['total_paid'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-warning-600 dark:text-warning-400">
                                        {{ $section['summary']['total_remaining'] }}
                                    </td>
                                    <td @class([
                                        'whitespace-nowrap px-4 py-3 text-right font-mono font-bold',
                                        'text-danger-600 dark:text-danger-400' => $section['summary']['total_overdue'],
                                        'text-gray-300 dark:text-gray-600' => !$section['summary']['total_overdue'],
                                    ])>
                                        {{ $section['summary']['total_overdue'] ?? "\u{2014}" }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 dark:border-gray-700 dark:bg-white/5">
                        <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            No {{ $section['type'] === 'client' ? 'invoices' : 'purchase orders' }} found.
                        </span>
                    </div>
                @endif

                {{-- Unallocated Payments --}}
                @if (count($section['unallocated_payments']) > 0)
                    <div class="mt-6 rounded-xl border-2 border-dashed border-warning-400 bg-warning-50 p-4 dark:border-warning-500/40 dark:bg-warning-500/5">
                        <div class="mb-3 flex items-center gap-3">
                            <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-warning-200 dark:bg-warning-500/20">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-warning-600 dark:text-warning-400" />
                            </div>
                            <div>
                                <p class="text-sm font-bold text-warning-800 dark:text-warning-300">Unallocated Payments</p>
                                <p class="text-xs text-warning-600 dark:text-warning-400">Funds not yet allocated to any invoice or order.</p>
                            </div>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-warning-200 dark:border-warning-500/20">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-warning-100 dark:bg-warning-500/10">
                                        <th class="px-4 py-2 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-warning-800 dark:text-warning-300">Payment</th>
                                        <th class="px-4 py-2 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-warning-800 dark:text-warning-300">Date</th>
                                        <th class="px-4 py-2 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-warning-800 dark:text-warning-300">Total Amount</th>
                                        <th class="px-4 py-2 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-warning-800 dark:text-warning-300">Unallocated</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['unallocated_payments'] as $payment)
                                        <tr class="border-t border-warning-200 dark:border-warning-500/20">
                                            <td class="whitespace-nowrap px-4 py-2">
                                                <a href="{{ $payment['url'] }}" class="font-semibold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400">
                                                    {{ $payment['reference'] }}
                                                </a>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-2 text-gray-500 dark:text-gray-400">{{ $payment['date'] }}</td>
                                            <td class="whitespace-nowrap px-4 py-2 text-right font-mono font-medium text-gray-900 dark:text-white">
                                                <span class="text-gray-400 dark:text-gray-500">{{ $payment['currency'] }}</span> {{ $payment['total'] }}
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-2 text-right font-mono font-bold text-warning-700 dark:text-warning-300">
                                                <span class="text-warning-500">{{ $payment['currency'] }}</span> {{ $payment['unallocated'] }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </x-filament::section>
        @endforeach

        @if (count($sections) === 0)
            <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-12 dark:border-gray-700 dark:bg-white/5">
                <x-filament::icon icon="heroicon-o-building-office-2" class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <span class="text-sm font-medium text-gray-500 dark:text-gray-400">No financial data available</span>
                <span class="text-xs text-gray-400 dark:text-gray-500">This company has no client or supplier role assigned.</span>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
