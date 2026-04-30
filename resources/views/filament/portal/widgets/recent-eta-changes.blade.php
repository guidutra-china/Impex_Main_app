<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('widgets.portal.recent_eta_changes')"
        icon="heroicon-o-arrow-path"
        :description="__('widgets.portal.recent_eta_changes_desc')"
    >
        <div class="space-y-3">
            @foreach ($shipments as $shipment)
                <div class="flex items-center justify-between rounded-lg border border-warning-200 bg-warning-50 p-3 dark:border-warning-500/20 dark:bg-warning-500/5">
                    <div class="flex items-center gap-3">
                        <x-filament::icon icon="heroicon-o-truck" class="h-6 w-6 text-warning-500" />
                        <div>
                            <p class="text-sm font-semibold text-warning-700 dark:text-warning-400">
                                {{ __('widgets.portal.eta_changed_for', ['ref' => $shipment->reference]) }}
                            </p>
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                {{ __('widgets.portal.eta_now', ['eta' => optional($shipment->eta)->format('d/m/Y') ?? '—']) }}
                                · {{ __('widgets.portal.changed_at', ['date' => $shipment->eta_changed_at->format('d/m/Y H:i')]) }}
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
