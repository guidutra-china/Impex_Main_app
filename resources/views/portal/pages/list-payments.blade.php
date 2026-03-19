<x-filament-panels::page>
    @if (method_exists($this, 'getHeaderWidgets') && count($this->getHeaderWidgets()) > 0)
        <x-filament-widgets::widgets
            :widgets="$this->getVisibleHeaderWidgets()"
            :columns="$this->getHeaderWidgetsColumns()"
            :data="['activeTab' => $this->activeTab ?? null]"
        />
    @endif

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

    {{ $this->table }}
</x-filament-panels::page>
