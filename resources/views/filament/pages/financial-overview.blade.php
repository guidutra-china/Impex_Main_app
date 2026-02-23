<x-filament-panels::page>
    @php
        $stats = $this->getStats();
    @endphp

    {{-- Summary Stats --}}
    <div class="grid grid-cols-1 gap-4 md:grid-cols-3 lg:grid-cols-6 mb-6">
        <x-filament::section>
            <div class="text-center">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pending Receivables</div>
                <div class="text-lg font-bold text-warning-600 dark:text-warning-400 mt-1">{{ $stats['pending_receivables'] }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Approved Receivables</div>
                <div class="text-lg font-bold text-success-600 dark:text-success-400 mt-1">{{ $stats['approved_receivables'] }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pending Payables</div>
                <div class="text-lg font-bold text-warning-600 dark:text-warning-400 mt-1">{{ $stats['pending_payables'] }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Approved Payables</div>
                <div class="text-lg font-bold text-danger-600 dark:text-danger-400 mt-1">{{ $stats['approved_payables'] }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Pending Add. Costs</div>
                <div class="text-lg font-bold text-info-600 dark:text-info-400 mt-1">{{ $stats['pending_additional_costs'] }}</div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <div class="text-center">
                <div class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Blocking Items</div>
                <div class="text-lg font-bold {{ $stats['blocking_schedule_items'] > 0 ? 'text-danger-600 dark:text-danger-400' : 'text-gray-400' }} mt-1">{{ $stats['blocking_schedule_items'] }}</div>
            </div>
        </x-filament::section>
    </div>

    {{-- Tab Navigation --}}
    <div class="mb-4">
        <nav class="flex space-x-1 rounded-xl bg-gray-100 dark:bg-gray-800 p-1" role="tablist">
            <button
                wire:click="switchTab('receivables')"
                @class([
                    'flex-1 rounded-lg py-2.5 px-3 text-sm font-medium leading-5 transition-all',
                    'bg-white dark:bg-gray-700 text-primary-700 dark:text-primary-400 shadow' => $activeTab === 'receivables',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' => $activeTab !== 'receivables',
                ])
                role="tab"
            >
                <x-heroicon-o-arrow-down-left class="w-4 h-4 inline-block mr-1" />
                Receivables (from Clients)
            </button>
            <button
                wire:click="switchTab('payables')"
                @class([
                    'flex-1 rounded-lg py-2.5 px-3 text-sm font-medium leading-5 transition-all',
                    'bg-white dark:bg-gray-700 text-primary-700 dark:text-primary-400 shadow' => $activeTab === 'payables',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' => $activeTab !== 'payables',
                ])
                role="tab"
            >
                <x-heroicon-o-arrow-up-right class="w-4 h-4 inline-block mr-1" />
                Payables (to Suppliers)
            </button>
            <button
                wire:click="switchTab('schedule')"
                @class([
                    'flex-1 rounded-lg py-2.5 px-3 text-sm font-medium leading-5 transition-all',
                    'bg-white dark:bg-gray-700 text-primary-700 dark:text-primary-400 shadow' => $activeTab === 'schedule',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' => $activeTab !== 'schedule',
                ])
                role="tab"
            >
                <x-heroicon-o-calendar-days class="w-4 h-4 inline-block mr-1" />
                Payment Schedule
            </button>
            <button
                wire:click="switchTab('additional_costs')"
                @class([
                    'flex-1 rounded-lg py-2.5 px-3 text-sm font-medium leading-5 transition-all',
                    'bg-white dark:bg-gray-700 text-primary-700 dark:text-primary-400 shadow' => $activeTab === 'additional_costs',
                    'text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300' => $activeTab !== 'additional_costs',
                ])
                role="tab"
            >
                <x-heroicon-o-receipt-percent class="w-4 h-4 inline-block mr-1" />
                Additional Costs
            </button>
        </nav>
    </div>

    {{-- Table --}}
    {{ $this->table }}
</x-filament-panels::page>
