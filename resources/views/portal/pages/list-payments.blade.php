<x-filament-panels::page>
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$currentView === 'payments'"
            wire:click="switchTab('payments')"
            icon="heroicon-o-banknotes"
        >
            {{ __('navigation.resources.payments') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$currentView === 'schedule'"
            wire:click="switchTab('schedule')"
            icon="heroicon-o-calendar-days"
        >
            {{ __('widgets.financial_stats.payment_schedule') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    @if ($currentView === 'payments')
        @if (count($this->getHeaderWidgets()))
            <x-filament-widgets::widgets
                :widgets="$this->getVisibleHeaderWidgets()"
                :columns="$this->getHeaderWidgetsColumns()"
                :data="$this->getWidgetData()"
            />
        @endif
    @endif

    {{ $this->table }}
</x-filament-panels::page>
