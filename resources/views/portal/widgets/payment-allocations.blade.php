<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('widgets.portal.payment_allocations')"
        icon="heroicon-o-arrows-right-left"
    >
        {{-- Summary Cards --}}
        <div class="grid grid-cols-2 gap-3 mb-6 sm:grid-cols-3">
            {{-- Total Amount --}}
            <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gray-200 dark:bg-white/10">
                        <x-filament::icon icon="heroicon-o-banknotes" class="h-5 w-5 text-gray-500 dark:text-gray-400" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.portal.payment_amount') }}</p>
                        <p class="truncate text-lg font-bold text-gray-900 dark:text-white">{{ $currency }} {{ $paymentAmount }}</p>
                    </div>
                </div>
            </div>

            {{-- Allocated --}}
            <div class="rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-500/20 dark:bg-success-500/5">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-success-200 dark:bg-success-500/20">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600 dark:text-success-400" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-success-600 dark:text-success-400">{{ __('widgets.list_stats.allocated') }}</p>
                        <p class="truncate text-lg font-bold text-success-700 dark:text-success-300">{{ $currency }} {{ $totalAllocated }}</p>
                    </div>
                </div>
            </div>

            {{-- Unallocated --}}
            <div @class([
                'rounded-xl border p-4',
                'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5' => $totalUnallocatedRaw > 0,
                'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => $totalUnallocatedRaw <= 0,
            ])>
                <div class="flex items-center gap-3">
                    <div @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                        'bg-warning-200 dark:bg-warning-500/20' => $totalUnallocatedRaw > 0,
                        'bg-gray-200 dark:bg-white/10' => $totalUnallocatedRaw <= 0,
                    ])>
                        @if ($totalUnallocatedRaw > 0)
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5 text-warning-600 dark:text-warning-400" />
                        @else
                            <x-filament::icon icon="heroicon-o-check" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                        @endif
                    </div>
                    <div class="min-w-0">
                        <p @class([
                            'text-[0.65rem] font-semibold uppercase tracking-wide',
                            'text-warning-600 dark:text-warning-400' => $totalUnallocatedRaw > 0,
                            'text-gray-400 dark:text-gray-500' => $totalUnallocatedRaw <= 0,
                        ])>{{ __('widgets.list_stats.unallocated') }}</p>
                        <p @class([
                            'truncate text-lg font-bold',
                            'text-warning-700 dark:text-warning-300' => $totalUnallocatedRaw > 0,
                            'text-gray-400 dark:text-gray-500' => $totalUnallocatedRaw <= 0,
                        ])>{{ $currency }} {{ $totalUnallocated }}</p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Allocations Table --}}
        @if (count($allocations) > 0)
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.portal.document') }}</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.portal.reference') }}</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.portal.schedule_item') }}</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.portal.allocated_amount') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($allocations as $index => $allocation)
                            <tr @class([
                                'border-b border-gray-100 dark:border-white/5',
                                'bg-gray-50/50 dark:bg-white/[0.02]' => $index % 2 === 1,
                            ])>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-400 dark:text-gray-500">
                                    {{ $index + 1 }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <x-filament::badge color="info" size="sm">
                                        {{ $allocation['document_type'] }}
                                    </x-filament::badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-semibold text-gray-900 dark:text-white">
                                    {{ $allocation['document_ref'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-gray-400">
                                    {{ $allocation['schedule_label'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium text-success-600 dark:text-success-400">
                                    <span class="text-success-400 dark:text-success-600">{{ $currency }}</span> {{ $allocation['amount'] }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <td colspan="4" class="px-4 py-3 text-sm font-bold text-gray-700 dark:text-gray-300">
                                {{ __('widgets.portal.total') }} ({{ count($allocations) }} {{ count($allocations) === 1 ? __('widgets.portal.allocation') : __('widgets.portal.allocations') }})
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-success-600 dark:text-success-400">
                                {{ $currency }} {{ $totalAllocated }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 dark:border-gray-700 dark:bg-white/5">
                <x-filament::icon icon="heroicon-o-arrows-right-left" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('widgets.portal.no_allocations_yet') }}</span>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
