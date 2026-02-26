<x-filament-widgets::widget>
    <div class="space-y-6">
        @foreach ($sections as $section)
            <x-filament::section
                :heading="$section['title']"
                :icon="$section['icon']"
                collapsible
            >
                {{-- Summary Stats --}}
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 mb-6">
                    {{-- Total --}}
                    <div class="relative overflow-hidden rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-100 dark:bg-white/10">
                                <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Total</p>
                                <p class="truncate text-lg font-bold text-gray-950 dark:text-white">{{ $section['summary']['total_invoiced'] }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Paid --}}
                    <div class="relative overflow-hidden rounded-xl bg-success-50 p-4 dark:bg-success-500/10">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-success-100 dark:bg-success-500/20">
                                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600 dark:text-success-400" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-success-600/70 dark:text-success-400/70">Paid</p>
                                <p class="truncate text-lg font-bold text-success-700 dark:text-success-400">{{ $section['summary']['total_paid'] }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Remaining --}}
                    <div class="relative overflow-hidden rounded-xl bg-warning-50 p-4 dark:bg-warning-500/10">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-warning-100 dark:bg-warning-500/20">
                                <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5 text-warning-600 dark:text-warning-400" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide text-warning-600/70 dark:text-warning-400/70">Remaining</p>
                                <p class="truncate text-lg font-bold text-warning-700 dark:text-warning-400">{{ $section['summary']['total_remaining'] }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Overdue --}}
                    <div class="relative overflow-hidden rounded-xl {{ $section['summary']['total_overdue'] ? 'bg-danger-50 dark:bg-danger-500/10' : 'bg-gray-50 dark:bg-white/5' }} p-4">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $section['summary']['total_overdue'] ? 'bg-danger-100 dark:bg-danger-500/20' : 'bg-gray-100 dark:bg-white/10' }}">
                                <x-filament::icon
                                    icon="{{ $section['summary']['total_overdue'] ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check' }}"
                                    class="h-5 w-5 {{ $section['summary']['total_overdue'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-400 dark:text-gray-500' }}"
                                />
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs font-medium uppercase tracking-wide {{ $section['summary']['total_overdue'] ? 'text-danger-600/70 dark:text-danger-400/70' : 'text-gray-500 dark:text-gray-400' }}">Overdue</p>
                                <p class="truncate text-lg font-bold {{ $section['summary']['total_overdue'] ? 'text-danger-700 dark:text-danger-400' : 'text-gray-400 dark:text-gray-500' }}">
                                    {{ $section['summary']['total_overdue'] ?? '—' }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Documents Table --}}
                @if (count($section['rows']) > 0)
                    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-white/10">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 bg-gray-50/80 dark:border-white/10 dark:bg-white/5">
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Reference</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Status</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Total</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Paid</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Remaining</th>
                                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Overdue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($section['rows'] as $row)
                                    <tr class="transition-colors hover:bg-gray-50/50 dark:hover:bg-white/[0.02]">
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <a href="{{ $row['url'] }}" class="font-semibold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400">
                                                {{ $row['reference'] }}
                                            </a>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-600 dark:text-gray-400">{{ $row['date'] }}</td>
                                        <td class="whitespace-nowrap px-4 py-3">
                                            <x-filament::badge :color="$row['status']->getColor()" size="sm">
                                                {{ $row['status']->getLabel() }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm font-medium text-gray-950 dark:text-white">
                                            <span class="text-gray-400 dark:text-gray-500">{{ $row['currency'] }}</span>
                                            {{ $row['total'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm font-medium text-success-600 dark:text-success-400">
                                            <span class="text-success-400 dark:text-success-600">{{ $row['currency'] }}</span>
                                            {{ $row['paid'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm font-medium text-warning-600 dark:text-warning-400">
                                            <span class="text-warning-400 dark:text-warning-600">{{ $row['currency'] }}</span>
                                            {{ $row['remaining'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm">
                                            @if ($row['overdue'])
                                                <span class="inline-flex items-center gap-1 font-semibold text-danger-600 dark:text-danger-400">
                                                    <span class="text-danger-400 dark:text-danger-600">{{ $row['currency'] }}</span>
                                                    {{ $row['overdue'] }}
                                                </span>
                                            @else
                                                <span class="text-gray-300 dark:text-gray-600">—</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            {{-- Table Footer with Totals --}}
                            <tfoot>
                                <tr class="border-t-2 border-gray-200 bg-gray-50/50 dark:border-white/10 dark:bg-white/[0.02]">
                                    <td class="px-4 py-3 text-sm font-bold text-gray-700 dark:text-gray-300" colspan="3">
                                        Total ({{ count($section['rows']) }} {{ count($section['rows']) === 1 ? 'record' : 'records' }})
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm font-bold text-gray-950 dark:text-white">
                                        {{ $section['summary']['total_invoiced'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm font-bold text-success-600 dark:text-success-400">
                                        {{ $section['summary']['total_paid'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm font-bold text-warning-600 dark:text-warning-400">
                                        {{ $section['summary']['total_remaining'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right font-mono text-sm font-bold {{ $section['summary']['total_overdue'] ? 'text-danger-600 dark:text-danger-400' : 'text-gray-300 dark:text-gray-600' }}">
                                        {{ $section['summary']['total_overdue'] ?? '—' }}
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="flex items-center justify-center gap-3 rounded-xl bg-gray-50 p-8 dark:bg-white/5">
                        <x-filament::icon icon="heroicon-o-document-magnifying-glass" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            No {{ $section['type'] === 'client' ? 'invoices' : 'purchase orders' }} found for this company.
                        </p>
                    </div>
                @endif

                {{-- Unallocated Payments --}}
                @if (count($section['unallocated_payments']) > 0)
                    <div class="mt-6 rounded-xl border-2 border-dashed border-warning-300 bg-warning-50/50 p-4 dark:border-warning-500/30 dark:bg-warning-500/5">
                        <div class="mb-3 flex items-center gap-2">
                            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-warning-100 dark:bg-warning-500/20">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-warning-600 dark:text-warning-400" />
                            </div>
                            <div>
                                <h4 class="text-sm font-bold text-warning-700 dark:text-warning-400">Unallocated Payments</h4>
                                <p class="text-xs text-warning-600/70 dark:text-warning-400/60">These payments have funds not yet allocated to any invoice or order.</p>
                            </div>
                        </div>
                        <div class="overflow-hidden rounded-lg border border-warning-200 dark:border-warning-500/20">
                            <table class="w-full text-sm">
                                <thead>
                                    <tr class="bg-warning-100/50 dark:bg-warning-500/10">
                                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-warning-700 dark:text-warning-400">Payment</th>
                                        <th class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-warning-700 dark:text-warning-400">Date</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-warning-700 dark:text-warning-400">Total Amount</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wider text-warning-700 dark:text-warning-400">Unallocated</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-warning-100 dark:divide-warning-500/10">
                                    @foreach ($section['unallocated_payments'] as $payment)
                                        <tr class="transition-colors hover:bg-warning-50 dark:hover:bg-warning-500/5">
                                            <td class="whitespace-nowrap px-4 py-2.5">
                                                <a href="{{ $payment['url'] }}" class="font-semibold text-primary-600 hover:text-primary-500 hover:underline dark:text-primary-400">
                                                    {{ $payment['reference'] }}
                                                </a>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-gray-400">{{ $payment['date'] }}</td>
                                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium text-gray-950 dark:text-white">
                                                <span class="text-gray-400 dark:text-gray-500">{{ $payment['currency'] }}</span>
                                                {{ $payment['total'] }}
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-bold text-warning-700 dark:text-warning-400">
                                                <span class="text-warning-500 dark:text-warning-600">{{ $payment['currency'] }}</span>
                                                {{ $payment['unallocated'] }}
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
            <div class="flex flex-col items-center justify-center gap-3 rounded-xl bg-gray-50 p-10 dark:bg-white/5">
                <x-filament::icon icon="heroicon-o-building-office" class="h-10 w-10 text-gray-300 dark:text-gray-600" />
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">No financial data available</p>
                <p class="text-xs text-gray-400 dark:text-gray-500">This company has no client or supplier role assigned.</p>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
