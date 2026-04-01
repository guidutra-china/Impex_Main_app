<x-filament-widgets::widget>
    @if ($items->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-5 w-5 text-primary-500" />
                    {{ __('widgets.recurring.heading') }} — {{ $currentMonthLabel }}
                </div>
            </x-slot>

            <x-slot name="description">
                @if ($pendingCount > 0)
                    <span class="text-warning-600 dark:text-warning-400">
                        {{ $pendingCount }} {{ __('widgets.recurring.pending') }}
                    </span>
                    ·
                @endif
                <span class="text-success-600 dark:text-success-400">
                    {{ $paidCount }} {{ __('widgets.recurring.paid') }}
                </span>
            </x-slot>

            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($items as $item)
                    <div @class([
                        'flex items-center justify-between gap-4 py-3',
                        'opacity-60' => $item['is_paid'],
                    ])>
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            {{-- Status icon --}}
                            @if ($item['is_paid'])
                                <div class="flex-shrink-0 rounded-full bg-success-50 p-1.5 dark:bg-success-500/10">
                                    <x-filament::icon icon="heroicon-m-check" class="h-4 w-4 text-success-500" />
                                </div>
                            @else
                                <div class="flex-shrink-0 rounded-full bg-warning-50 p-1.5 dark:bg-warning-500/10">
                                    <x-filament::icon icon="heroicon-m-clock" class="h-4 w-4 text-warning-500" />
                                </div>
                            @endif

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                        {{ $item['description'] }}
                                    </p>
                                    @if ($item['category'])
                                        <span @class([
                                            'inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset',
                                            'bg-' . $item['category']->getColor() . '-50 text-' . $item['category']->getColor() . '-700 ring-' . $item['category']->getColor() . '-600/20',
                                            'dark:bg-' . $item['category']->getColor() . '-500/10 dark:text-' . $item['category']->getColor() . '-400 dark:ring-' . $item['category']->getColor() . '-500/20',
                                        ])>
                                            {{ $item['category']->getLabel() }}
                                        </span>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 mt-0.5">
                                    @if ($item['recurring_day'])
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('widgets.recurring.due_day', ['day' => $item['recurring_day']]) }}
                                        </p>
                                    @endif
                                    @if ($item['payment_method'])
                                        <span class="text-xs text-gray-400 dark:text-gray-500">·</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">
                                            {{ $item['payment_method'] }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-3 flex-shrink-0">
                            <p class="text-sm font-semibold text-gray-900 dark:text-white whitespace-nowrap">
                                {{ $item['currency_code'] }} {{ $item['amount'] }}
                            </p>

                            @if ($item['is_paid'])
                                <span class="inline-flex items-center rounded-md bg-success-50 px-2 py-1 text-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                                    {{ __('widgets.recurring.paid') }}
                                </span>
                            @else
                                <x-filament::button
                                    size="xs"
                                    wire:click="registerPayment({{ $item['id'] }})"
                                    icon="heroicon-m-banknotes"
                                >
                                    {{ __('widgets.recurring.register_payment') }}
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
