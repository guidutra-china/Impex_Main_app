<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('widgets.arrivals.upcoming_arrivals')"
        icon="heroicon-o-calendar-days"
    >
        {{-- Week Summary Cards --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
            @foreach ($weeks as $week)
                @php
                    $colors = match ($week['index']) {
                        0 => ['border' => 'border-warning-200 dark:border-warning-500/20', 'bg' => 'bg-warning-50 dark:bg-warning-500/5', 'icon_bg' => 'bg-warning-200 dark:bg-warning-500/20', 'icon' => 'text-warning-600 dark:text-warning-400', 'title' => 'text-warning-600 dark:text-warning-400', 'count' => 'text-warning-700 dark:text-warning-300'],
                        1 => ['border' => 'border-info-200 dark:border-info-500/20', 'bg' => 'bg-info-50 dark:bg-info-500/5', 'icon_bg' => 'bg-info-200 dark:bg-info-500/20', 'icon' => 'text-info-600 dark:text-info-400', 'title' => 'text-info-600 dark:text-info-400', 'count' => 'text-info-700 dark:text-info-300'],
                        2 => ['border' => 'border-primary-200 dark:border-primary-500/20', 'bg' => 'bg-primary-50 dark:bg-primary-500/5', 'icon_bg' => 'bg-primary-200 dark:bg-primary-500/20', 'icon' => 'text-primary-600 dark:text-primary-400', 'title' => 'text-primary-600 dark:text-primary-400', 'count' => 'text-primary-700 dark:text-primary-300'],
                        default => ['border' => 'border-gray-200 dark:border-white/10', 'bg' => 'bg-gray-50 dark:bg-white/5', 'icon_bg' => 'bg-gray-200 dark:bg-white/10', 'icon' => 'text-gray-500 dark:text-gray-400', 'title' => 'text-gray-500 dark:text-gray-400', 'count' => 'text-gray-700 dark:text-gray-300'],
                    };
                    $hasShipments = $week['count'] > 0;
                    $emptyColors = ['border' => 'border-gray-200 dark:border-white/10', 'bg' => 'bg-gray-50 dark:bg-white/5', 'icon_bg' => 'bg-gray-200 dark:bg-white/10', 'icon' => 'text-gray-400 dark:text-gray-500', 'title' => 'text-gray-400 dark:text-gray-500', 'count' => 'text-gray-400 dark:text-gray-500'];
                    $c = $hasShipments ? $colors : $emptyColors;
                @endphp
                <div class="rounded-xl border p-4 {{ $c['border'] }} {{ $c['bg'] }}">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $c['icon_bg'] }}">
                            <x-filament::icon icon="heroicon-o-truck" class="h-5 w-5 {{ $c['icon'] }}" />
                        </div>
                        <div class="min-w-0">
                            <p class="text-[0.65rem] font-semibold uppercase tracking-wide {{ $c['title'] }}">{{ $week['label'] }}</p>
                            <p class="text-lg font-bold {{ $c['count'] }}">
                                {{ $hasShipments ? $week['count'] : '—' }}
                            </p>
                            <p class="text-[0.65rem] text-gray-400 dark:text-gray-500">{{ $week['range'] }}</p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Detailed Shipment List --}}
        @php
            $allShipments = collect($weeks)->flatMap(fn ($week) =>
                $week['shipments']->map(fn ($s) => ['shipment' => $s, 'week_index' => $week['index'], 'week_label' => $week['label']])
            );
        @endphp

        @if ($allShipments->isNotEmpty())
            <div class="mt-4 overflow-x-auto rounded-xl border border-gray-200 dark:border-white/10">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.arrivals.reference') }}</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.arrivals.status') }}</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.arrivals.transport') }}</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.arrivals.container') }}</th>
                            <th class="px-4 py-3 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.arrivals.eta') }}</th>
                            <th class="px-4 py-3 text-center text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.arrivals.days_until') }}</th>
                            <th class="px-4 py-3 text-left text-[0.7rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('widgets.arrivals.destination') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($allShipments as $index => $entry)
                            @php
                                $shipment = $entry['shipment'];
                                $daysUntil = $shipment->eta ? (int) now()->startOfDay()->diffInDays($shipment->eta, false) : null;
                                $statusColor = is_string($shipment->status->getColor()) ? $shipment->status->getColor() : 'gray';
                            @endphp
                            <tr @class([
                                'border-b border-gray-100 dark:border-white/5',
                                'bg-gray-50/50 dark:bg-white/[0.02]' => $index % 2 === 1,
                            ])>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <div class="font-semibold text-gray-900 dark:text-white">{{ $shipment->reference ?? '—' }}</div>
                                    @if ($shipment->bl_number)
                                        <div class="text-xs text-gray-500 dark:text-gray-400">B/L: {{ $shipment->bl_number }}</div>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5">
                                    <span @class([
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[0.65rem] font-semibold',
                                        match ($statusColor) {
                                            'gray' => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400',
                                            'warning' => 'bg-warning-100 text-warning-700 dark:bg-warning-500/20 dark:text-warning-400',
                                            'danger' => 'bg-danger-100 text-danger-700 dark:bg-danger-500/20 dark:text-danger-400',
                                            'success' => 'bg-success-100 text-success-700 dark:bg-success-500/20 dark:text-success-400',
                                            'info' => 'bg-info-100 text-info-700 dark:bg-info-500/20 dark:text-info-400',
                                            'primary' => 'bg-primary-100 text-primary-700 dark:bg-primary-500/20 dark:text-primary-400',
                                            default => 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400',
                                        },
                                    ])>
                                        @if ($shipment->status->getIcon())
                                            <x-filament::icon :icon="$shipment->status->getIcon()" class="h-3.5 w-3.5" />
                                        @endif
                                        {{ $shipment->status->getLabel() }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-gray-400">
                                    {{ $shipment->transport_mode?->getLabel() ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-gray-400">
                                    {{ $shipment->container_number ?: '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-center font-medium text-gray-900 dark:text-white">
                                    {{ $shipment->eta?->format('d/m/Y') ?? '—' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-center">
                                    @if ($daysUntil !== null)
                                        @if ($daysUntil < 0)
                                            <span class="inline-flex items-center rounded-full bg-danger-100 px-2 py-0.5 text-[0.65rem] font-bold text-danger-700 dark:bg-danger-500/20 dark:text-danger-400">
                                                {{ abs($daysUntil) }}d {{ __('widgets.arrivals.late') }}
                                            </span>
                                        @elseif ($daysUntil === 0)
                                            <span class="inline-flex items-center rounded-full bg-success-100 px-2 py-0.5 text-[0.65rem] font-bold text-success-700 dark:bg-success-500/20 dark:text-success-400">
                                                {{ __('widgets.arrivals.today') }}
                                            </span>
                                        @elseif ($daysUntil <= 3)
                                            <span class="inline-flex items-center rounded-full bg-warning-100 px-2 py-0.5 text-[0.65rem] font-medium text-warning-700 dark:bg-warning-500/20 dark:text-warning-400">
                                                {{ $daysUntil }}d
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[0.65rem] font-medium text-gray-600 dark:bg-white/10 dark:text-gray-400">
                                                {{ $daysUntil }}d
                                            </span>
                                        @endif
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-gray-600 dark:text-gray-400">
                                    {{ $shipment->destination_port ?: '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="mt-4 flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-10 dark:border-gray-700 dark:bg-white/5">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-8 w-8 text-success-400 dark:text-success-600" />
                <span class="text-sm font-medium text-success-600 dark:text-success-400">{{ __('widgets.arrivals.no_upcoming') }}</span>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
