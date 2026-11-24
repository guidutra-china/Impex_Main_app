@php
    use App\Domain\Infrastructure\Support\Money;

    $currency = $report['payment']['currency'];
    $th = 'px-3 py-2.5 text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'whitespace-nowrap px-3 py-2';
    $muted = 'text-gray-600 dark:text-gray-400';
    $leadingColumns = 8;
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        @foreach ([
            [__('widgets.portal.payment_amount'), $report['payment']['amount'], false],
            [__('widgets.list_stats.allocated'), $report['totals']['allocated'], false],
            [__('widgets.list_stats.unallocated'), $report['totals']['unallocated'], $report['totals']['unallocated'] > 0],
        ] as [$label, $amount, $highlight])
            <div @class([
                'rounded-xl border p-3',
                'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5' => $highlight,
                'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => ! $highlight,
            ])>
                <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</p>
                <p class="text-base font-bold text-gray-900 dark:text-white">{{ $currency }} {{ Money::format($amount) }}</p>
            </div>
        @endforeach
    </div>

    @if (count($report['rows']) > 0)
        <div class="overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                        <th class="{{ $th }} text-left">#</th>
                        <th class="{{ $th }} text-left">{{ __('widgets.portal.document') }}</th>
                        <th class="{{ $th }} text-left">{{ __('widgets.portal.reference') }}</th>
                        <th class="{{ $th }} text-left">{{ __('widgets.portal.allocation_details.pi') }}</th>
                        <th class="{{ $th }} text-left">{{ __('forms.labels.client_reference') }}</th>
                        <th class="{{ $th }} text-left">{{ __('widgets.portal.allocation_details.shipment') }}</th>
                        <th class="{{ $th }} text-left">{{ __('widgets.portal.allocation_details.bl') }}</th>
                        <th class="{{ $th }} text-left">{{ __('widgets.portal.schedule_item') }}</th>
                        <th class="{{ $th }} text-right">{{ __('widgets.portal.allocated_amount') }}</th>
                        @if ($report['has_exchange'])
                            <th class="{{ $th }} text-right">{{ __('forms.labels.exchange_rate') }}</th>
                        @endif
                        @if ($report['show_document_amount'])
                            <th class="{{ $th }} text-right">{{ __('widgets.portal.allocation_details.document_amount') }}</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr @class([
                            'border-b border-gray-100 dark:border-white/5',
                            'bg-gray-50/50 dark:bg-white/[0.02]' => $loop->even,
                        ])>
                            <td class="{{ $td }} text-gray-400 dark:text-gray-500">{{ $row['index'] }}</td>
                            <td class="{{ $td }}">
                                <x-filament::badge color="info" size="sm">{{ $row['document_type'] }}</x-filament::badge>
                            </td>
                            <td class="{{ $td }} font-semibold text-gray-900 dark:text-white">{{ $row['document_ref'] }}</td>
                            <td class="{{ $td }} {{ $muted }}">
                                @forelse ($row['pi_breakdown'] as $part)
                                    {{-- Custo do embarque rateado pelo valor dos produtos de cada PI nele. --}}
                                    <div class="flex items-baseline justify-between gap-4">
                                        <span>{{ $part['reference'] }}</span>
                                        <span class="font-mono text-gray-900 dark:text-white">{{ $part['currency'] }} {{ Money::format($part['amount']) }}</span>
                                    </div>
                                @empty
                                    {{ $row['pi_ref'] ?? '—' }}
                                @endforelse
                            </td>
                            <td class="{{ $td }} {{ $muted }}">{{ $row['client_reference'] ?? '—' }}</td>
                            <td class="{{ $td }} {{ $muted }}">{{ $row['shipment_ref'] ?? '—' }}</td>
                            <td class="{{ $td }} {{ $muted }}">{{ $row['bl_number'] ?? '—' }}</td>
                            <td class="px-3 py-2 text-gray-600 dark:text-gray-400">{{ $row['description'] }}</td>
                            <td class="{{ $td }} text-right font-mono font-medium text-success-600 dark:text-success-400">
                                {{ $currency }} {{ Money::format($row['allocated']) }}
                            </td>
                            @if ($report['has_exchange'])
                                <td class="{{ $td }} {{ $muted }} text-right font-mono">
                                    {{ $row['exchange_rate'] !== null ? rtrim(rtrim(number_format($row['exchange_rate'], 8, '.', ''), '0'), '.') : '—' }}
                                </td>
                            @endif
                            @if ($report['show_document_amount'])
                                <td class="{{ $td }} {{ $muted }} text-right font-mono">
                                    {{ $row['document_currency'] }} {{ Money::format($row['document_amount']) }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                        <td colspan="{{ $leadingColumns }}" class="px-3 py-2.5 text-sm font-bold text-gray-700 dark:text-gray-300">
                            {{ __('widgets.portal.total') }} ({{ count($report['rows']) }} {{ count($report['rows']) === 1 ? __('widgets.portal.allocation') : __('widgets.portal.allocations') }})
                        </td>
                        <td class="whitespace-nowrap px-3 py-2.5 text-right font-mono font-bold text-success-600 dark:text-success-400">
                            {{ $currency }} {{ Money::format($report['totals']['allocated']) }}
                        </td>
                        @if ($report['has_exchange'])
                            <td></td>
                        @endif
                        @if ($report['show_document_amount'])
                            <td></td>
                        @endif
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
</div>
