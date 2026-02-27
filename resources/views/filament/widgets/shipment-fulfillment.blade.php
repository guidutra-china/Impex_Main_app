<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('widgets.fulfillment.shipment_fulfillment')"
        icon="heroicon-o-truck"
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

        {{-- Fulfillment Progress Bar --}}
        @if ($progress !== null)
            <div class="mb-6">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">{{ __('widgets.fulfillment.fulfillment_progress') }}</span>
                    <span @class([
                        'text-xs font-bold',
                        'text-success-600 dark:text-success-400' => $progress >= 100,
                        'text-primary-600 dark:text-primary-400' => $progress > 0 && $progress < 100,
                        'text-gray-400 dark:text-gray-500' => $progress == 0,
                    ])>{{ $progress }}%</span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div @class([
                        'h-full rounded-full transition-all duration-500',
                        'bg-success-500' => $progress >= 100,
                        'bg-primary-500' => $progress > 0 && $progress < 100,
                        'bg-gray-300 dark:bg-gray-600' => $progress == 0,
                    ]) style="width: {{ min($progress, 100) }}%"></div>
                </div>
            </div>
        @endif

        {{-- Reconciliation Table --}}
        @if (count($items) > 0)
            <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">#</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.fulfillment.product') }}</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.fulfillment.pi_qty') }}</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.fulfillment.shipped') }}</th>
                            <th class="px-4 py-3 text-right text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.fulfillment.remaining') }}</th>
                            <th class="px-4 py-3 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.fulfillment.status') }}</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.fulfillment.shipments') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($items as $index => $item)
                            <tr @class([
                                'border-b border-gray-100 dark:border-white/5',
                                'bg-gray-50/50 dark:bg-white/[0.02]' => $index % 2 === 1,
                            ])>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-400 dark:text-gray-500">
                                    {{ $index + 1 }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-gray-900 dark:text-white">
                                    {{ $item['product_name'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono text-gray-600 dark:text-gray-400">
                                    {{ number_format($item['quantity']) }} {{ $item['unit'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium">
                                    <span @class([
                                        'text-success-600 dark:text-success-400' => $item['shipped'] >= $item['quantity'],
                                        'text-primary-600 dark:text-primary-400' => $item['shipped'] > 0 && $item['shipped'] < $item['quantity'],
                                        'text-gray-400 dark:text-gray-500' => $item['shipped'] === 0,
                                    ])>{{ number_format($item['shipped']) }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right font-mono font-medium">
                                    @if ($item['remaining'] <= 0)
                                        <span class="text-success-600 dark:text-success-400">0</span>
                                    @else
                                        <span class="text-warning-600 dark:text-warning-400">{{ number_format($item['remaining']) }}</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-center">
                                    @if ($item['status'] === 'fully_shipped')
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.65rem] font-semibold bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400">
                                            <x-filament::icon icon="heroicon-m-check-circle" class="h-3.5 w-3.5" />
                                            {{ __('widgets.fulfillment.shipped') }}
                                        </span>
                                    @elseif ($item['status'] === 'partial')
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.65rem] font-semibold bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400">
                                            <x-filament::icon icon="heroicon-m-arrow-path" class="h-3.5 w-3.5" />
                                            {{ __('widgets.fulfillment.partial') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.65rem] font-semibold bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400">
                                            <x-filament::icon icon="heroicon-m-clock" class="h-3.5 w-3.5" />
                                            {{ __('widgets.fulfillment.pending_status') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    @if (!empty($item['shipment_refs']))
                                        <div class="flex flex-wrap gap-1">
                                            @foreach ($item['shipment_refs'] as $ref)
                                                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[0.6rem] font-semibold bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-300">
                                                    {{ $ref }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <td colspan="2" class="px-4 py-3 text-sm font-bold text-gray-700 dark:text-gray-300">
                                {{ __('widgets.portal.total') }} ({{ count($items) }} {{ count($items) === 1 ? __('widgets.fulfillment.item') : __('widgets.fulfillment.items') }})
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-gray-900 dark:text-white">
                                {{ number_format($totals['quantity']) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-mono font-bold text-success-600 dark:text-success-400">
                                {{ number_format($totals['shipped']) }}
                            </td>
                            <td @class([
                                'whitespace-nowrap px-4 py-3 text-right font-mono font-bold',
                                'text-warning-600 dark:text-warning-400' => $totals['remaining'] > 0,
                                'text-success-600 dark:text-success-400' => $totals['remaining'] <= 0,
                            ])>
                                {{ number_format($totals['remaining']) }}
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @else
            <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 dark:border-gray-700 dark:bg-white/5">
                <x-filament::icon icon="heroicon-o-cube" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('widgets.fulfillment.no_items') }}</span>
            </div>
        @endif

        {{-- Finalization Readiness --}}
        @if ($showFinalizationStatus ?? false)
            <div class="mt-4 flex items-center gap-3 rounded-xl border-2 border-dashed px-4 py-3
                {{ $isFullyShipped
                    ? 'border-success-400 bg-success-50 dark:border-success-500/40 dark:bg-success-500/5'
                    : 'border-warning-400 bg-warning-50 dark:border-warning-500/40 dark:bg-warning-500/5' }}">
                <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg
                    {{ $isFullyShipped
                        ? 'bg-success-200 dark:bg-success-500/20'
                        : 'bg-warning-200 dark:bg-warning-500/20' }}">
                    <x-filament::icon
                        :icon="$isFullyShipped ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                        @class([
                            'h-4 w-4',
                            'text-success-600 dark:text-success-400' => $isFullyShipped,
                            'text-warning-600 dark:text-warning-400' => !$isFullyShipped,
                        ])
                    />
                </div>
                <div>
                    <p @class([
                        'text-sm font-bold',
                        'text-success-800 dark:text-success-300' => $isFullyShipped,
                        'text-warning-800 dark:text-warning-300' => !$isFullyShipped,
                    ])>
                        {{ $isFullyShipped ? __('widgets.fulfillment.ready_for_finalization') : __('widgets.fulfillment.fulfillment_incomplete') }}
                    </p>
                    @if (!$isFullyShipped)
                        <p class="text-xs text-warning-600 dark:text-warning-400">
                            {{ __('widgets.fulfillment.units_pending_shipment', ['units' => $totals['remaining'], 'items' => $pendingItemsCount]) }}
                        </p>
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
