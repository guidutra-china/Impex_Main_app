<x-filament-widgets::widget>
    <x-filament::section
        :heading="$heading"
        :icon="$icon"
    >
        {{-- Summary Cards --}}
        <div class="grid grid-cols-2 gap-3 mb-6 sm:grid-cols-{{ count($cards) }}">
            @foreach ($cards as $card)
                <div @class([
                    'rounded-xl border p-4',
                    match ($card['color']) {
                        'gray' => 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5',
                        'primary' => 'border-primary-200 bg-primary-50 dark:border-primary-500/20 dark:bg-primary-500/5',
                        'success' => 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/5',
                        'warning' => 'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5',
                        'danger' => 'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/5',
                        'info' => 'border-info-200 bg-info-50 dark:border-info-500/20 dark:bg-info-500/5',
                        default => 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5',
                    },
                ])>
                    <div class="flex items-center gap-3">
                        <div @class([
                            'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                            match ($card['color']) {
                                'gray' => 'bg-gray-200 dark:bg-white/10',
                                'primary' => 'bg-primary-200 dark:bg-primary-500/20',
                                'success' => 'bg-success-200 dark:bg-success-500/20',
                                'warning' => 'bg-warning-200 dark:bg-warning-500/20',
                                'danger' => 'bg-danger-200 dark:bg-danger-500/20',
                                'info' => 'bg-info-200 dark:bg-info-500/20',
                                default => 'bg-gray-200 dark:bg-white/10',
                            },
                        ])>
                            <x-filament::icon :icon="$card['icon']" @class([
                                'h-5 w-5',
                                match ($card['color']) {
                                    'gray' => 'text-gray-500 dark:text-gray-400',
                                    'primary' => 'text-primary-600 dark:text-primary-400',
                                    'success' => 'text-success-600 dark:text-success-400',
                                    'warning' => 'text-warning-600 dark:text-warning-400',
                                    'danger' => 'text-danger-600 dark:text-danger-400',
                                    'info' => 'text-info-600 dark:text-info-400',
                                    default => 'text-gray-500 dark:text-gray-400',
                                },
                            ]) />
                        </div>
                        <div class="min-w-0">
                            <p @class([
                                'text-[0.65rem] font-semibold uppercase tracking-wide',
                                match ($card['color']) {
                                    'gray' => 'text-gray-500 dark:text-gray-400',
                                    'primary' => 'text-primary-600 dark:text-primary-400',
                                    'success' => 'text-success-600 dark:text-success-400',
                                    'warning' => 'text-warning-600 dark:text-warning-400',
                                    'danger' => 'text-danger-600 dark:text-danger-400',
                                    'info' => 'text-info-600 dark:text-info-400',
                                    default => 'text-gray-500 dark:text-gray-400',
                                },
                            ])>{{ $card['label'] }}</p>
                            <p @class([
                                'truncate text-lg font-bold',
                                match ($card['color']) {
                                    'gray' => 'text-gray-900 dark:text-white',
                                    'primary' => 'text-primary-700 dark:text-primary-300',
                                    'success' => 'text-success-700 dark:text-success-300',
                                    'warning' => 'text-warning-700 dark:text-warning-300',
                                    'danger' => 'text-danger-700 dark:text-danger-300',
                                    'info' => 'text-info-700 dark:text-info-300',
                                    default => 'text-gray-900 dark:text-white',
                                },
                            ])>{{ $card['value'] }}</p>
                            @if (!empty($card['description']))
                                <p @class([
                                    'text-[0.65rem]',
                                    match ($card['color']) {
                                        'gray' => 'text-gray-400 dark:text-gray-500',
                                        'primary' => 'text-primary-500 dark:text-primary-500',
                                        'success' => 'text-success-500 dark:text-success-500',
                                        'warning' => 'text-warning-500 dark:text-warning-500',
                                        'danger' => 'text-danger-500 dark:text-danger-500',
                                        'info' => 'text-info-500 dark:text-info-500',
                                        default => 'text-gray-400 dark:text-gray-500',
                                    },
                                ])>{{ $card['description'] }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Payment Progress Bar --}}
        @if ($progress !== null)
            <div class="mb-6">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">Payment Progress</span>
                    <span @class([
                        'text-xs font-bold',
                        'text-success-600 dark:text-success-400' => $progress >= 100,
                        'text-primary-600 dark:text-primary-400' => $progress > 0 && $progress < 100,
                        'text-gray-400 dark:text-gray-500' => $progress === 0,
                    ])>{{ $progress }}%</span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div @class([
                        'h-full rounded-full transition-all duration-500',
                        'bg-success-500' => $progress >= 100,
                        'bg-primary-500' => $progress > 0 && $progress < 100,
                        'bg-gray-300 dark:bg-gray-600' => $progress === 0,
                    ]) style="width: {{ min($progress, 100) }}%"></div>
                </div>
            </div>
        @endif

        {{-- Payment Schedule Table --}}
        @if (count($scheduleItems) > 0)
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Stage</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Status</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Due Date</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">%</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Amount</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Paid</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Remaining</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scheduleItems as $index => $item)
                            <tr @class([
                                'border-b border-gray-100 dark:border-white/5',
                                'bg-gray-50/50 dark:bg-white/[0.02]' => $index % 2 === 1,
                            ])>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-400 dark:text-gray-500">
                                    {{ $index + 1 }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <div class="flex items-center gap-2">
                                        @if ($item['is_credit'])
                                            <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-400">Credit</span>
                                        @endif
                                        @if ($item['is_blocking'])
                                            <x-filament::icon icon="heroicon-m-lock-closed" class="h-3.5 w-3.5 text-danger-500 dark:text-danger-400" />
                                        @endif
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $item['label'] }}</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <x-filament::badge :color="$item['status']->getColor()" :icon="$item['status']->getIcon()" size="sm">
                                        {{ $item['status']->getLabel() }}
                                    </x-filament::badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    @if ($item['due_date'])
                                        <span @class([
                                            'text-gray-600 dark:text-gray-400',
                                            'font-semibold text-danger-600 dark:text-danger-400' => $item['status'] === \App\Domain\Financial\Enums\PaymentScheduleStatus::OVERDUE,
                                        ])>{{ $item['due_date'] }}</span>
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-gray-500 dark:text-gray-400">
                                    @if ($item['percentage'])
                                        {{ $item['percentage'] }}%
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium text-gray-900 dark:text-white">
                                    @if ($item['is_credit'])
                                        <span class="text-info-600 dark:text-info-400">-{{ $item['amount'] }}</span>
                                    @else
                                        <span class="text-gray-400 dark:text-gray-500">{{ $currency }}</span> {{ $item['amount'] }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium">
                                    @if ($item['is_credit'])
                                        <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                    @else
                                        <span class="text-success-600 dark:text-success-400">{{ $item['paid'] }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium">
                                    @if ($item['is_credit'])
                                        <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                    @elseif ($item['remaining_raw'] <= 0)
                                        <span class="text-success-600 dark:text-success-400">0.00</span>
                                    @else
                                        <span @class([
                                            'text-warning-600 dark:text-warning-400' => $item['status'] !== \App\Domain\Financial\Enums\PaymentScheduleStatus::OVERDUE,
                                            'font-bold text-danger-600 dark:text-danger-400' => $item['status'] === \App\Domain\Financial\Enums\PaymentScheduleStatus::OVERDUE,
                                        ])>{{ $item['remaining'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <td colspan="5" class="px-4 py-3 text-sm font-bold text-gray-700 dark:text-gray-300">
                                Total ({{ count($scheduleItems) }} {{ count($scheduleItems) === 1 ? 'stage' : 'stages' }})
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-gray-900 dark:text-white">
                                <span class="text-gray-400 dark:text-gray-500">{{ $currency }}</span> {{ $totals['amount'] }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-success-600 dark:text-success-400">
                                {{ $totals['paid'] }}
                            </td>
                            <td @class([
                                'whitespace-nowrap px-4 py-3 text-right font-mono font-bold',
                                'text-warning-600 dark:text-warning-400' => $totals['remaining_raw'] > 0,
                                'text-success-600 dark:text-success-400' => $totals['remaining_raw'] <= 0,
                            ])>
                                {{ $totals['remaining'] }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 dark:border-gray-700 dark:bg-white/5">
                <x-filament::icon icon="heroicon-o-calendar-days" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                <span class="text-sm text-gray-500 dark:text-gray-400">No payment schedule defined.</span>
            </div>
        @endif

        {{-- Unallocated Payments Alert --}}
        @if ($unallocatedTotal > 0)
            <div class="mt-4 flex items-center gap-3 rounded-xl border-2 border-dashed border-warning-400 bg-warning-50 px-4 py-3 dark:border-warning-500/40 dark:bg-warning-500/5">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-warning-200 dark:bg-warning-500/20">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-4 w-4 text-warning-600 dark:text-warning-400" />
                </div>
                <div>
                    <p class="text-sm font-bold text-warning-800 dark:text-warning-300">
                        {{ $currency }} {{ $unallocatedFormatted }} unallocated
                    </p>
                    <p class="text-xs text-warning-600 dark:text-warning-400">
                        {{ $unallocatedLabel }}
                    </p>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
