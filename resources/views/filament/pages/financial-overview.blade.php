<x-filament-panels::page>
    {{-- Tab Navigation --}}
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$activeTab === 'receivables'"
            wire:click="switchTab('receivables')"
            icon="heroicon-o-arrow-down-left"
        >
            {{ __('widgets.financial_stats.receivables_from_clients') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'payables'"
            wire:click="switchTab('payables')"
            icon="heroicon-o-arrow-up-right"
        >
            {{ __('widgets.financial_stats.payables_to_suppliers') }}
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'schedule'"
            wire:click="switchTab('schedule')"
            icon="heroicon-o-calendar-days"
        >
            {{ __('widgets.financial_stats.payment_schedule') }}
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- Table --}}
    {{ $this->table }}
</x-filament-panels::page>
