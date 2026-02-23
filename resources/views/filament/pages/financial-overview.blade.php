<x-filament-panels::page>
    {{-- Tab Navigation --}}
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$activeTab === 'receivables'"
            wire:click="switchTab('receivables')"
            icon="heroicon-o-arrow-down-left"
        >
            Receivables (from Clients)
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'payables'"
            wire:click="switchTab('payables')"
            icon="heroicon-o-arrow-up-right"
        >
            Payables (to Suppliers)
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'schedule'"
            wire:click="switchTab('schedule')"
            icon="heroicon-o-calendar-days"
        >
            Payment Schedule
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$activeTab === 'additional_costs'"
            wire:click="switchTab('additional_costs')"
            icon="heroicon-o-receipt-percent"
        >
            Additional Costs
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{-- Table --}}
    {{ $this->table }}
</x-filament-panels::page>
