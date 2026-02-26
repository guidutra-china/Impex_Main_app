<x-filament-widgets::widget>
    <div class="space-y-6">
        @foreach ($sections as $section)
            <x-filament::section
                :heading="$section['title']"
                :icon="$section['icon']"
                collapsible
            >
                {{-- Summary Cards --}}
                <div class="grid grid-cols-2 gap-4 mb-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Total</p>
                        <p class="text-lg font-semibold text-gray-950 dark:text-white">{{ $section['summary']['total_invoiced'] }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Paid</p>
                        <p class="text-lg font-semibold text-success-600 dark:text-success-400">{{ $section['summary']['total_paid'] }}</p>
                    </div>
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Remaining</p>
                        <p class="text-lg font-semibold text-warning-600 dark:text-warning-400">{{ $section['summary']['total_remaining'] }}</p>
                    </div>
                    @if ($section['summary']['total_overdue'])
                        <div class="rounded-lg bg-danger-50 p-3 dark:bg-danger-500/10">
                            <p class="text-xs font-medium text-danger-600 dark:text-danger-400">Overdue</p>
                            <p class="text-lg font-semibold text-danger-600 dark:text-danger-400">{{ $section['summary']['total_overdue'] }}</p>
                        </div>
                    @endif
                </div>

                {{-- Documents Table --}}
                @if (count($section['rows']) > 0)
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-white/5">
                                    <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Reference</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Date</th>
                                    <th class="px-4 py-2 text-left font-medium text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Total</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Paid</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Remaining</th>
                                    <th class="px-4 py-2 text-right font-medium text-gray-500 dark:text-gray-400">Overdue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($section['rows'] as $row)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-white/5">
                                        <td class="px-4 py-2">
                                            <a href="{{ $row['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                {{ $row['reference'] }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $row['date'] }}</td>
                                        <td class="px-4 py-2">
                                            <x-filament::badge :color="$row['status']->getColor()">
                                                {{ $row['status']->getLabel() }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="px-4 py-2 text-right font-mono text-gray-950 dark:text-white">{{ $row['currency'] }} {{ $row['total'] }}</td>
                                        <td class="px-4 py-2 text-right font-mono text-success-600 dark:text-success-400">{{ $row['currency'] }} {{ $row['paid'] }}</td>
                                        <td class="px-4 py-2 text-right font-mono text-warning-600 dark:text-warning-400">{{ $row['currency'] }} {{ $row['remaining'] }}</td>
                                        <td class="px-4 py-2 text-right font-mono {{ $row['overdue'] ? 'text-danger-600 dark:text-danger-400 font-semibold' : 'text-gray-400' }}">
                                            {{ $row['overdue'] ? $row['currency'] . ' ' . $row['overdue'] : '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="rounded-lg bg-gray-50 p-4 text-center text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
                        No {{ $section['type'] === 'client' ? 'invoices' : 'purchase orders' }} found.
                    </div>
                @endif

                {{-- Unallocated Payments --}}
                @if (count($section['unallocated_payments']) > 0)
                    <div class="mt-4">
                        <h4 class="mb-2 flex items-center gap-2 text-sm font-medium text-warning-600 dark:text-warning-400">
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4" />
                            Unallocated Payments
                        </h4>
                        <div class="overflow-x-auto rounded-lg border border-warning-200 dark:border-warning-500/20">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-warning-50 dark:bg-warning-500/10">
                                        <th class="px-4 py-2 text-left font-medium text-warning-700 dark:text-warning-400">Payment</th>
                                        <th class="px-4 py-2 text-left font-medium text-warning-700 dark:text-warning-400">Date</th>
                                        <th class="px-4 py-2 text-right font-medium text-warning-700 dark:text-warning-400">Total</th>
                                        <th class="px-4 py-2 text-right font-medium text-warning-700 dark:text-warning-400">Unallocated</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-warning-100 dark:divide-warning-500/10">
                                    @foreach ($section['unallocated_payments'] as $payment)
                                        <tr class="hover:bg-warning-50/50 dark:hover:bg-warning-500/5">
                                            <td class="px-4 py-2">
                                                <a href="{{ $payment['url'] }}" class="font-medium text-primary-600 hover:underline dark:text-primary-400">
                                                    {{ $payment['reference'] }}
                                                </a>
                                            </td>
                                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">{{ $payment['date'] }}</td>
                                            <td class="px-4 py-2 text-right font-mono text-gray-950 dark:text-white">{{ $payment['currency'] }} {{ $payment['total'] }}</td>
                                            <td class="px-4 py-2 text-right font-mono font-semibold text-warning-600 dark:text-warning-400">{{ $payment['currency'] }} {{ $payment['unallocated'] }}</td>
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
            <div class="rounded-xl bg-gray-50 p-6 text-center text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">
                This company has no client or supplier role assigned. Financial statements are only available for companies with client or supplier roles.
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
