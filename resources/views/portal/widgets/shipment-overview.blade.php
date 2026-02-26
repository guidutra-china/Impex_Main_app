<x-filament-widgets::widget>
    {{-- Status & Key Info Cards --}}
    <div class="grid grid-cols-2 gap-3 mb-4 sm:grid-cols-{{ count($cards) }}">
        @foreach ($cards as $card)
            <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <div class="flex items-center gap-3">
                    <div @class([
                        'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                        'bg-primary-100 dark:bg-primary-500/20' => $card['color'] === 'primary',
                        'bg-success-100 dark:bg-success-500/20' => $card['color'] === 'success',
                        'bg-warning-100 dark:bg-warning-500/20' => $card['color'] === 'warning',
                        'bg-danger-100 dark:bg-danger-500/20' => $card['color'] === 'danger',
                        'bg-info-100 dark:bg-info-500/20' => $card['color'] === 'info',
                        'bg-gray-100 dark:bg-white/10' => $card['color'] === 'gray',
                    ])>
                        <x-filament::icon :icon="$card['icon']" @class([
                            'h-5 w-5',
                            'text-primary-600 dark:text-primary-400' => $card['color'] === 'primary',
                            'text-success-600 dark:text-success-400' => $card['color'] === 'success',
                            'text-warning-600 dark:text-warning-400' => $card['color'] === 'warning',
                            'text-danger-600 dark:text-danger-400' => $card['color'] === 'danger',
                            'text-info-600 dark:text-info-400' => $card['color'] === 'info',
                            'text-gray-500 dark:text-gray-400' => $card['color'] === 'gray',
                        ]) />
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $card['label'] }}</p>
                        <p @class([
                            'truncate text-lg font-bold',
                            'text-primary-600 dark:text-primary-400' => $card['color'] === 'primary',
                            'text-success-600 dark:text-success-400' => $card['color'] === 'success',
                            'text-warning-600 dark:text-warning-400' => $card['color'] === 'warning',
                            'text-danger-600 dark:text-danger-400' => $card['color'] === 'danger',
                            'text-info-600 dark:text-info-400' => $card['color'] === 'info',
                            'text-gray-900 dark:text-white' => $card['color'] === 'gray',
                        ])>{{ $card['value'] }}</p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- Logistics Details --}}
        <x-filament::section heading="Logistics Details" icon="heroicon-o-globe-alt">
            <div class="grid grid-cols-2 gap-4">
                @foreach ($logistics as $item)
                    <div>
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $item['label'] }}</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['value'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 border-t border-gray-100 pt-4 dark:border-white/5">
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Packages</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $totalPackages }}</p>
                    </div>
                    <div>
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Gross Weight</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $totalGrossWeight }}</p>
                    </div>
                    <div>
                        <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Volume</p>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $totalVolume }}</p>
                    </div>
                </div>
            </div>

            @if ($piReferences !== '—')
                <div class="mt-4 border-t border-gray-100 pt-4 dark:border-white/5">
                    <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Proforma Invoices</p>
                    <p class="text-sm font-semibold text-primary-600 dark:text-primary-400">{{ $piReferences }}</p>
                </div>
            @endif
        </x-filament::section>

        {{-- Timeline & Documents --}}
        <div class="space-y-4">
            {{-- Timeline --}}
            @if (count($timeline) > 0)
                <x-filament::section heading="Shipping Timeline" icon="heroicon-o-clock">
                    <div class="space-y-4">
                        @foreach ($timeline as $event)
                            <div class="flex items-start gap-3">
                                <div @class([
                                    'flex h-8 w-8 shrink-0 items-center justify-center rounded-full',
                                    'bg-success-100 dark:bg-success-500/20' => $event['completed'],
                                    'bg-gray-100 dark:bg-white/10' => ! $event['completed'],
                                ])>
                                    @if ($event['completed'])
                                        <x-filament::icon icon="heroicon-o-check" class="h-4 w-4 text-success-600 dark:text-success-400" />
                                    @else
                                        <x-filament::icon :icon="$event['icon']" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                                    @endif
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ $event['label'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        Estimated: {{ $event['date'] }}
                                        @if ($event['actual'])
                                            <span class="ml-1 font-semibold text-success-600 dark:text-success-400">Actual: {{ $event['actual'] }}</span>
                                        @endif
                                    </p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            {{-- Documents --}}
            <x-filament::section heading="Documents" icon="heroicon-o-document-arrow-down">
                @if (count($documents) > 0)
                    <div class="space-y-2">
                        @foreach ($documents as $doc)
                            <div class="flex items-center justify-between rounded-lg border border-gray-100 bg-gray-50 px-4 py-3 dark:border-white/5 dark:bg-white/5">
                                <div class="flex items-center gap-3">
                                    <x-filament::icon icon="heroicon-o-document" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                                    <div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white">{{ $doc['name'] }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $doc['type'] }} &middot; {{ $doc['created_at'] }}</p>
                                    </div>
                                </div>
                                <a href="{{ $doc['download_url'] }}" class="inline-flex items-center gap-1 rounded-lg bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-600 transition hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-400 dark:hover:bg-primary-500/20">
                                    <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-3.5 w-3.5" />
                                    Download
                                </a>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 bg-gray-50 p-8 dark:border-gray-700 dark:bg-white/5">
                        <x-filament::icon icon="heroicon-o-document" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                        <span class="text-sm text-gray-500 dark:text-gray-400">No documents available yet.</span>
                    </div>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-widgets::widget>
