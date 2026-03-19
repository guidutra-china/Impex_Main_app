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
            <div
                x-data="{
                    items: @js($scheduleItems),
                    statusOptions: @js($statusOptions),
                    filterStatus: '',
                    sortCol: '',
                    sortDir: 'asc',
                    currency: @js($currency),
                    totals: @js($totals),
                    toggleSort(col) {
                        if (this.sortCol === col) {
                            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
                        } else {
                            this.sortCol = col;
                            this.sortDir = 'asc';
                        }
                    },
                    get filteredItems() {
                        let result = this.items;
                        if (this.filterStatus) {
                            result = result.filter(i => i.status_value === this.filterStatus);
                        }
                        if (this.sortCol) {
                            const col = this.sortCol;
                            const dir = this.sortDir === 'asc' ? 1 : -1;
                            result = [...result].sort((a, b) => {
                                let va = a[col], vb = b[col];
                                if (va === null || va === undefined || va === '') va = col.includes('raw') || col === 'percentage' ? -Infinity : '';
                                if (vb === null || vb === undefined || vb === '') vb = col.includes('raw') || col === 'percentage' ? -Infinity : '';
                                if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * dir;
                                return String(va).localeCompare(String(vb)) * dir;
                            });
                        }
                        return result;
                    },
                    get filteredTotals() {
                        if (!this.filterStatus) return this.totals;
                        const filtered = this.items.filter(i => i.status_value === this.filterStatus && !i.is_credit);
                        const amount = filtered.reduce((s, i) => s + i.amount_raw, 0);
                        const paid = filtered.reduce((s, i) => s + i.paid_raw, 0);
                        const remaining = Math.max(0, amount - paid);
                        return {
                            amount: this.formatMoney(amount),
                            paid: this.formatMoney(paid),
                            remaining: this.formatMoney(remaining),
                            remaining_raw: remaining,
                        };
                    },
                    formatMoney(v) {
                        return (v / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                    },
                    badgeClasses(color) {
                        const map = {
                            gray: 'bg-gray-100 text-gray-700 dark:bg-gray-500/20 dark:text-gray-400',
                            primary: 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-400',
                            success: 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400',
                            warning: 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400',
                            danger: 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400',
                            info: 'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-400',
                        };
                        return map[color] || map.gray;
                    },
                }"
            >
                {{-- Filter Bar --}}
                <div class="mb-3 flex items-center gap-3">
                    <select
                        x-model="filterStatus"
                        class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-300"
                    >
                        <option value="">All statuses</option>
                        <template x-for="opt in statusOptions" :key="opt.value">
                            <option :value="opt.value" x-text="opt.label"></option>
                        </template>
                    </select>
                    <span
                        x-show="filterStatus"
                        x-on:click="filterStatus = ''; sortCol = ''; sortDir = 'asc'"
                        class="cursor-pointer text-xs text-primary-600 hover:text-primary-800 dark:text-primary-400 dark:hover:text-primary-300"
                    >Clear filters</span>
                    <span class="ml-auto text-xs text-gray-400 dark:text-gray-500" x-text="filteredItems.length + ' of ' + items.length + ' stages'"></span>
                </div>

                <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                                <th
                                    x-on:click="toggleSort('label')"
                                    class="cursor-pointer select-none px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    <span class="inline-flex items-center gap-1">
                                        Stage
                                        <template x-if="sortCol === 'label'">
                                            <svg class="h-3 w-3" :class="sortDir === 'desc' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </template>
                                    </span>
                                </th>
                                <th
                                    x-on:click="toggleSort('status_value')"
                                    class="cursor-pointer select-none px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    <span class="inline-flex items-center gap-1">
                                        Status
                                        <template x-if="sortCol === 'status_value'">
                                            <svg class="h-3 w-3" :class="sortDir === 'desc' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </template>
                                    </span>
                                </th>
                                <th
                                    x-on:click="toggleSort('due_date_sort')"
                                    class="cursor-pointer select-none px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    <span class="inline-flex items-center gap-1">
                                        Due Date
                                        <template x-if="sortCol === 'due_date_sort'">
                                            <svg class="h-3 w-3" :class="sortDir === 'desc' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </template>
                                    </span>
                                </th>
                                <th
                                    x-on:click="toggleSort('percentage')"
                                    class="cursor-pointer select-none px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    <span class="inline-flex items-center justify-end gap-1">
                                        %
                                        <template x-if="sortCol === 'percentage'">
                                            <svg class="h-3 w-3" :class="sortDir === 'desc' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </template>
                                    </span>
                                </th>
                                <th
                                    x-on:click="toggleSort('amount_raw')"
                                    class="cursor-pointer select-none px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    <span class="inline-flex items-center justify-end gap-1">
                                        Amount
                                        <template x-if="sortCol === 'amount_raw'">
                                            <svg class="h-3 w-3" :class="sortDir === 'desc' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </template>
                                    </span>
                                </th>
                                <th
                                    x-on:click="toggleSort('paid_raw')"
                                    class="cursor-pointer select-none px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    <span class="inline-flex items-center justify-end gap-1">
                                        Paid
                                        <template x-if="sortCol === 'paid_raw'">
                                            <svg class="h-3 w-3" :class="sortDir === 'desc' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </template>
                                    </span>
                                </th>
                                <th
                                    x-on:click="toggleSort('remaining_raw')"
                                    class="cursor-pointer select-none px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                                >
                                    <span class="inline-flex items-center justify-end gap-1">
                                        Remaining
                                        <template x-if="sortCol === 'remaining_raw'">
                                            <svg class="h-3 w-3" :class="sortDir === 'desc' && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </template>
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(item, index) in filteredItems" :key="item.label + '-' + index">
                                <tr :class="index % 2 === 1 ? 'border-b border-gray-100 dark:border-white/5 bg-gray-50/50 dark:bg-white/[0.02]' : 'border-b border-gray-100 dark:border-white/5'">
                                    <td class="whitespace-nowrap px-4 py-2.5 text-gray-400 dark:text-gray-500" x-text="index + 1"></td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <template x-if="item.is_credit">
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[0.6rem] font-semibold uppercase bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-400">Credit</span>
                                            </template>
                                            <template x-if="item.is_blocking">
                                                <svg class="h-3.5 w-3.5 text-danger-500 dark:text-danger-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" /></svg>
                                            </template>
                                            <span class="font-medium text-gray-900 dark:text-white" x-text="item.label"></span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <span
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium"
                                            :class="badgeClasses(item.status_color)"
                                            x-text="item.status_label"
                                        ></span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <template x-if="item.due_date">
                                            <span
                                                :class="item.status_value === 'overdue' ? 'font-semibold text-danger-600 dark:text-danger-400' : 'text-gray-600 dark:text-gray-400'"
                                                x-text="item.due_date"
                                            ></span>
                                        </template>
                                        <template x-if="!item.due_date">
                                            <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                        </template>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-gray-500 dark:text-gray-400">
                                        <template x-if="item.percentage">
                                            <span x-text="item.percentage + '%'"></span>
                                        </template>
                                        <template x-if="!item.percentage">
                                            <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                        </template>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium text-gray-900 dark:text-white">
                                        <template x-if="item.is_credit">
                                            <span class="text-info-600 dark:text-info-400" x-text="'-' + item.amount"></span>
                                        </template>
                                        <template x-if="!item.is_credit">
                                            <span><span class="text-gray-400 dark:text-gray-500" x-text="currency"></span> <span x-text="item.amount"></span></span>
                                        </template>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium">
                                        <template x-if="item.is_credit">
                                            <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                        </template>
                                        <template x-if="!item.is_credit">
                                            <span class="text-success-600 dark:text-success-400" x-text="item.paid"></span>
                                        </template>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium">
                                        <template x-if="item.is_credit">
                                            <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                        </template>
                                        <template x-if="!item.is_credit && item.remaining_raw <= 0">
                                            <span class="text-success-600 dark:text-success-400">0.00</span>
                                        </template>
                                        <template x-if="!item.is_credit && item.remaining_raw > 0">
                                            <span
                                                :class="item.status_value === 'overdue' ? 'font-bold text-danger-600 dark:text-danger-400' : 'text-warning-600 dark:text-warning-400'"
                                                x-text="item.remaining"
                                            ></span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                <td colspan="5" class="px-4 py-3 text-sm font-bold text-gray-700 dark:text-gray-300">
                                    <span x-text="'Total (' + filteredItems.length + (filteredItems.length === 1 ? ' stage)' : ' stages)')"></span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-gray-900 dark:text-white">
                                    <span class="text-gray-400 dark:text-gray-500" x-text="currency"></span> <span x-text="filteredTotals.amount"></span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-success-600 dark:text-success-400" x-text="filteredTotals.paid"></td>
                                <td
                                    class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold"
                                    :class="filteredTotals.remaining_raw > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400'"
                                    x-text="filteredTotals.remaining"
                                ></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
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
