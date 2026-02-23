<x-filament-panels::page>
    @php
        $stats = $this->getStats();
    @endphp

    {{-- Summary Stats --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {{-- Pending Receivables --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Pending Receivables</p>
            <p class="mt-1 text-xl font-semibold text-amber-600 dark:text-amber-400">{{ $stats['pending_receivables'] }}</p>
        </div>

        {{-- Approved Receivables --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Approved Receivables</p>
            <p class="mt-1 text-xl font-semibold text-emerald-600 dark:text-emerald-400">{{ $stats['approved_receivables'] }}</p>
        </div>

        {{-- Pending Payables --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Pending Payables</p>
            <p class="mt-1 text-xl font-semibold text-amber-600 dark:text-amber-400">{{ $stats['pending_payables'] }}</p>
        </div>

        {{-- Approved Payables --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Approved Payables</p>
            <p class="mt-1 text-xl font-semibold text-red-600 dark:text-red-400">{{ $stats['approved_payables'] }}</p>
        </div>

        {{-- Pending Add. Costs --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Pending Add. Costs</p>
            <p class="mt-1 text-xl font-semibold text-sky-600 dark:text-sky-400">{{ $stats['pending_additional_costs'] }}</p>
        </div>

        {{-- Blocking Items --}}
        <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <p class="text-xs font-medium text-gray-500 dark:text-gray-400">Blocking Items</p>
            <p class="mt-1 text-xl font-semibold {{ $stats['blocking_count'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500' }}">{{ $stats['blocking_schedule_items'] }}</p>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div class="mt-6">
        <nav class="flex space-x-1 rounded-xl bg-gray-100 p-1 dark:bg-gray-800" role="tablist">
            <button
                wire:click="switchTab('receivables')"
                type="button"
                @class([
                    'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                    'bg-white text-primary-600 shadow-sm dark:bg-gray-700 dark:text-primary-400' => $activeTab === 'receivables',
                    'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => $activeTab !== 'receivables',
                ])
                role="tab"
            >
                Receivables (from Clients)
            </button>
            <button
                wire:click="switchTab('payables')"
                type="button"
                @class([
                    'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                    'bg-white text-primary-600 shadow-sm dark:bg-gray-700 dark:text-primary-400' => $activeTab === 'payables',
                    'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => $activeTab !== 'payables',
                ])
                role="tab"
            >
                Payables (to Suppliers)
            </button>
            <button
                wire:click="switchTab('schedule')"
                type="button"
                @class([
                    'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                    'bg-white text-primary-600 shadow-sm dark:bg-gray-700 dark:text-primary-400' => $activeTab === 'schedule',
                    'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => $activeTab !== 'schedule',
                ])
                role="tab"
            >
                Payment Schedule
            </button>
            <button
                wire:click="switchTab('additional_costs')"
                type="button"
                @class([
                    'flex-1 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                    'bg-white text-primary-600 shadow-sm dark:bg-gray-700 dark:text-primary-400' => $activeTab === 'additional_costs',
                    'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => $activeTab !== 'additional_costs',
                ])
                role="tab"
            >
                Additional Costs
            </button>
        </nav>
    </div>

    {{-- Table --}}
    <div class="mt-4">
        {{ $this->table }}
    </div>
</x-filament-panels::page>
