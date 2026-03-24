<x-filament-panels::page>
    @if (count($this->getHeaderWidgets()))
        <x-filament-widgets::widgets
            :columns="$this->getHeaderWidgetsColumns()"
            :widgets="$this->getVisibleHeaderWidgets()"
        />
    @endif

    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$activeTab === 'payments'"
            wire:click="switchTab('payments')"
            icon="heroicon-o-banknotes"
        >
            {{ __('navigation.resources.payments') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'schedule'"
            wire:click="switchTab('schedule')"
            icon="heroicon-o-calendar-days"
        >
            {{ __('widgets.financial_stats.payment_schedule') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
