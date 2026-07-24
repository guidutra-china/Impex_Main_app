<x-filament-widgets::widget>
    @if (count($blocks) > 0)
        <div class="grid gap-4 {{ count($blocks) > 1 ? 'lg:grid-cols-2' : '' }}">
            @foreach ($blocks as $block)
                <div class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                        {{ $block['title'] }}
                    </h3>

                    @foreach ($block['sections'] as $section)
                        <div @class(['mt-3', 'border-t border-gray-100 pt-3 dark:border-gray-800' => ! $loop->first])>
                            {{-- Progress bar --}}
                            <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                                <span>{{ $section['currency'] }}</span>
                                <span>{{ $section['totals']['percent_paid'] }}% {{ __('messages.shipment_payments_paid') }}</span>
                            </div>
                            <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                <div
                                    class="h-full rounded-full bg-success-500"
                                    style="width: {{ min(100, $section['totals']['percent_paid']) }}%"
                                ></div>
                            </div>

                            {{-- Stage rows --}}
                            <ul class="mt-3 space-y-2">
                                @foreach ($section['stages'] as $stage)
                                    <li class="flex items-center justify-between gap-3 text-sm">
                                        <span class="flex min-w-0 items-center gap-2">
                                            <x-filament::icon
                                                :icon="$stage['icon']"
                                                @class([
                                                    'h-4 w-4 shrink-0',
                                                    'text-success-500' => $stage['color'] === 'success',
                                                    'text-warning-500' => $stage['color'] === 'warning',
                                                    'text-danger-500' => $stage['color'] === 'danger',
                                                    'text-gray-400' => $stage['color'] === 'gray',
                                                ])
                                            />
                                            <span class="truncate text-gray-700 dark:text-gray-300">
                                                {{ $stage['label'] }}
                                                @if ($stage['prorated'])
                                                    <span class="text-xs text-gray-400">({{ __('messages.shipment_payments_prorated') }})</span>
                                                @endif
                                            </span>
                                        </span>
                                        <span class="shrink-0 text-right">
                                            <span class="font-medium">
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('messages.shipment_payments_paid_label') }}</span>
                                                <span @class([
                                                    'text-success-600 dark:text-success-400' => ! $stage['has_difference'],
                                                    'text-warning-600 dark:text-warning-400' => $stage['has_difference'] && $stage['status'] === 'partial',
                                                    'text-danger-600 dark:text-danger-400' => $stage['has_difference'] && $stage['status'] !== 'partial',
                                                ])>{{ $stage['paid'] }}</span>
                                                <span class="text-gray-400">/</span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">{{ __('messages.shipment_payments_total') }}</span>
                                                <span class="text-gray-900 dark:text-white">{{ $stage['amount'] }}</span>
                                            </span>
                                            <span class="block text-xs">
                                                @if ($stage['has_difference'])
                                                    <span @class([
                                                        'text-warning-600 dark:text-warning-400' => $stage['status'] === 'partial',
                                                        'text-danger-600 dark:text-danger-400' => $stage['status'] !== 'partial',
                                                    ])>
                                                        {{ __('messages.shipment_payments_outstanding') }}: {{ $stage['remaining'] }}
                                                    </span>
                                                    <span class="text-gray-400">·</span>
                                                @endif
                                                <span class="text-gray-500 dark:text-gray-400">
                                                    @if ($stage['status'] === 'paid')
                                                        {{ __('messages.shipment_payments_status_paid') }}
                                                    @elseif ($stage['status'] === 'overdue')
                                                        {{ __('messages.shipment_payments_status_overdue') }}@if ($stage['due_date']) — {{ $stage['due_date'] }}@endif
                                                    @elseif ($stage['due_date'])
                                                        {{ __('messages.shipment_payments_due') }} {{ $stage['due_date'] }}
                                                    @elseif ($stage['status'] === 'partial')
                                                        {{ __('messages.shipment_payments_status_partial') }}
                                                    @else
                                                        {{ __('messages.shipment_payments_status_pending') }}
                                                    @endif
                                                </span>
                                            </span>
                                        </span>
                                    </li>
                                @endforeach
                            </ul>

                            {{-- Totals footer --}}
                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-gray-100 pt-2 text-xs dark:border-gray-800">
                                <span class="text-success-600 dark:text-success-400">
                                    {{ __('messages.shipment_payments_paid_label') }}: <strong>{{ $section['currency'] }} {{ $section['totals']['paid'] }}</strong>
                                </span>
                                <span class="text-warning-600 dark:text-warning-400">
                                    {{ __('messages.shipment_payments_outstanding') }}: <strong>{{ $section['currency'] }} {{ $section['totals']['remaining'] }}</strong>
                                </span>
                                <span class="text-gray-600 dark:text-gray-300">
                                    {{ __('messages.shipment_payments_total') }}: <strong>{{ $section['currency'] }} {{ $section['totals']['amount'] }}</strong>
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif
</x-filament-widgets::widget>
