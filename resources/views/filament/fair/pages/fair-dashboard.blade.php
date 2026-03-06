<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Active Fair Banner --}}
        @php
            $activeFair = $this->getActiveFair();
        @endphp

        @if($activeFair)
            <div class="rounded-xl bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="flex-shrink-0">
                            <x-heroicon-o-flag class="w-8 h-8 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-emerald-900 dark:text-emerald-100">
                                {{ $activeFair->name }}
                            </h3>
                            <p class="text-sm text-emerald-700 dark:text-emerald-300">
                                @if($activeFair->location)
                                    {{ $activeFair->location }}
                                @endif
                                @if($activeFair->start_date)
                                    &middot; {{ $activeFair->start_date->format('M d, Y') }}
                                    @if($activeFair->end_date)
                                        — {{ $activeFair->end_date->format('M d, Y') }}
                                    @endif
                                @endif
                            </p>
                        </div>
                    </div>
                    <x-filament::button
                        color="gray"
                        size="sm"
                        wire:click="clearActiveFair"
                        icon="heroicon-o-x-mark"
                    >
                        Clear
                    </x-filament::button>
                </div>
            </div>
        @else
            <div class="rounded-xl bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 p-4">
                <div class="flex items-center gap-3">
                    <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-amber-600 dark:text-amber-400" />
                    <p class="text-amber-800 dark:text-amber-200">
                        No active fair selected. Start a new registration to select one.
                    </p>
                </div>
            </div>
        @endif

        {{-- Quick Stats --}}
        <div class="grid grid-cols-2 gap-4">
            <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4 text-center">
                <div class="text-3xl font-bold text-primary-600 dark:text-primary-400">
                    {{ $this->getTotalSuppliersToday() }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Suppliers Today
                </div>
            </div>
            <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10 p-4 text-center">
                <div class="text-3xl font-bold text-primary-600 dark:text-primary-400">
                    {{ $this->getTotalProductsToday() }}
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Products Today
                </div>
            </div>
        </div>

        {{-- Quick Action --}}
        <div class="flex justify-center">
            <x-filament::button
                tag="a"
                :href="App\Filament\Fair\Pages\RegisterAtFair::getUrl()"
                size="xl"
                icon="heroicon-o-plus-circle"
                class="w-full justify-center py-4 text-lg"
            >
                Register New Supplier
            </x-filament::button>
        </div>

        {{-- Recent Suppliers --}}
        <div class="rounded-xl bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                    Recent Registrations
                </h3>
            </div>

            @php
                $suppliers = $this->getRecentSuppliers();
            @endphp

            @if($suppliers->isEmpty())
                <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                    <x-heroicon-o-building-office class="w-12 h-12 mx-auto mb-2 text-gray-300 dark:text-gray-600" />
                    <p>No suppliers registered yet.</p>
                    <p class="text-sm mt-1">Start by registering your first supplier at the fair.</p>
                </div>
            @else
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($suppliers as $supplier)
                        <div class="p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 min-w-0">
                                    <h4 class="text-sm font-semibold text-gray-900 dark:text-white truncate">
                                        {{ $supplier->name }}
                                    </h4>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                        {{ $supplier->address_city }}{{ $supplier->address_country ? ', ' . $supplier->address_country : '' }}
                                    </p>
                                    @if($supplier->contacts->isNotEmpty())
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                            {{ $supplier->contacts->first()->name }}
                                            @if($supplier->contacts->first()->email)
                                                &middot; {{ $supplier->contacts->first()->email }}
                                            @endif
                                        </p>
                                    @endif
                                    @if($supplier->categories->isNotEmpty())
                                        <div class="flex flex-wrap gap-1 mt-1.5">
                                            @foreach($supplier->categories->take(3) as $category)
                                                <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-600 dark:text-gray-300">
                                                    {{ $category->name }}
                                                </span>
                                            @endforeach
                                            @if($supplier->categories->count() > 3)
                                                <span class="inline-flex items-center rounded-full bg-gray-100 dark:bg-gray-700 px-2 py-0.5 text-xs text-gray-600 dark:text-gray-300">
                                                    +{{ $supplier->categories->count() - 3 }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-shrink-0 ml-2">
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ $supplier->created_at->format('H:i') }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

    </div>
</x-filament-panels::page>
