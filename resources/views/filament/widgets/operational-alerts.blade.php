<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('widgets.alerts.action_required')"
        icon="heroicon-o-bell-alert"
        :description="count($alerts) > 0 ? count($alerts) . ' item' . (count($alerts) > 1 ? 's' : '') . ' need attention' : null"
    >
        @if (count($alerts) > 0)
            <div class="space-y-3">
                @foreach ($alerts as $alert)
                    <div @class([
                        'flex items-center justify-between rounded-lg border p-3',
                        'border-danger-200 bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/5' => $alert['type'] === 'danger',
                        'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5' => $alert['type'] === 'warning',
                        'border-info-200 bg-info-50 dark:border-info-500/20 dark:bg-info-500/5' => $alert['type'] === 'info',
                        'border-primary-200 bg-primary-50 dark:border-primary-500/20 dark:bg-primary-500/5' => $alert['type'] === 'primary',
                    ])>
                        <div class="flex items-center gap-3">
                            <x-filament::icon
                                :icon="$alert['icon']"
                                @class([
                                    'h-6 w-6',
                                    'text-danger-500' => $alert['type'] === 'danger',
                                    'text-warning-500' => $alert['type'] === 'warning',
                                    'text-info-500' => $alert['type'] === 'info',
                                    'text-primary-500' => $alert['type'] === 'primary',
                                ])
                            />
                            <div>
                                <p @class([
                                    'text-sm font-semibold',
                                    'text-danger-700 dark:text-danger-400' => $alert['type'] === 'danger',
                                    'text-warning-700 dark:text-warning-400' => $alert['type'] === 'warning',
                                    'text-info-700 dark:text-info-400' => $alert['type'] === 'info',
                                    'text-primary-700 dark:text-primary-400' => $alert['type'] === 'primary',
                                ])>
                                    {{ $alert['title'] }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $alert['description'] }}</p>
                            </div>
                        </div>
                        <a href="{{ $alert['url'] }}" @class([
                            'shrink-0 rounded-lg px-3 py-1.5 text-xs font-medium transition-colors',
                            'bg-danger-100 text-danger-700 hover:bg-danger-200 dark:bg-danger-500/10 dark:text-danger-400 dark:hover:bg-danger-500/20' => $alert['type'] === 'danger',
                            'bg-warning-100 text-warning-700 hover:bg-warning-200 dark:bg-warning-500/10 dark:text-warning-400 dark:hover:bg-warning-500/20' => $alert['type'] === 'warning',
                            'bg-info-100 text-info-700 hover:bg-info-200 dark:bg-info-500/10 dark:text-info-400 dark:hover:bg-info-500/20' => $alert['type'] === 'info',
                            'bg-primary-100 text-primary-700 hover:bg-primary-200 dark:bg-primary-500/10 dark:text-primary-400 dark:hover:bg-primary-500/20' => $alert['type'] === 'primary',
                        ])>
                            {{ $alert['action'] }}
                        </a>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex items-center gap-3 rounded-lg bg-success-50 p-4 dark:bg-success-500/5">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-6 w-6 text-success-500" />
                <p class="text-sm font-medium text-success-700 dark:text-success-400">{{ __('widgets.alerts.all_clear') }}</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
