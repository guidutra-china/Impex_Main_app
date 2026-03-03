<x-filament-panels::page>
    @php
        $data = $this->getComparisonData();
        $supplierQuotations = $data['supplier_quotations'];
        $rows = $data['rows'];
        $selections = $this->selections;
    @endphp

    @if($supplierQuotations->isEmpty())
        <x-filament::section>
            <div class="text-center py-8">
                <x-heroicon-o-document-magnifying-glass class="mx-auto h-12 w-12 text-gray-400" />
                <h3 class="mt-2 text-sm font-semibold text-gray-900 dark:text-white">No Supplier Quotations</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    No supplier quotations with status Received, Under Analysis, or Selected found for this inquiry.
                </p>
            </div>
        </x-filament::section>
    @else
        {{-- Summary Cards --}}
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-primary-600">{{ count($rows) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Products</div>
                </div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-info-600">{{ $supplierQuotations->count() }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Suppliers Quoting</div>
                </div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-success-600">{{ count($selections) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Items Selected</div>
                </div>
            </x-filament::section>
            <x-filament::section>
                <div class="text-center">
                    <div class="text-2xl font-bold text-warning-600">{{ count($rows) - count($selections) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Pending Selection</div>
                </div>
            </x-filament::section>
        </div>

        {{-- Quick Actions --}}
        <div class="flex flex-wrap gap-2 mb-6">
            <x-filament::button
                wire:click="selectBestPrices"
                color="success"
                size="sm"
                icon="heroicon-o-bolt"
            >
                Auto-Select Best Prices
            </x-filament::button>

            @foreach($supplierQuotations as $sq)
                <x-filament::button
                    wire:click="selectAllFromSupplier({{ $sq->id }})"
                    color="gray"
                    size="sm"
                    icon="heroicon-o-building-office"
                >
                    Select All: {{ $sq->company->name }}
                </x-filament::button>
            @endforeach
        </div>

        {{-- Comparison Table --}}
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 min-w-[200px] sticky left-0 bg-white dark:bg-gray-900 z-10">
                                Product
                            </th>
                            <th class="text-center py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 min-w-[80px]">
                                Qty
                            </th>
                            <th class="text-center py-3 px-3 font-semibold text-gray-700 dark:text-gray-300 min-w-[100px]">
                                Target
                            </th>
                            @foreach($supplierQuotations as $sq)
                                <th class="text-center py-3 px-4 font-semibold min-w-[180px] border-l border-gray-200 dark:border-gray-700"
                                    style="color: {{ ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'][$loop->index % 6] }}">
                                    <div>{{ $sq->company->name }}</div>
                                    <div class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $sq->reference }}</div>
                                    <div class="text-xs font-normal">
                                        <x-filament::badge :color="$sq->status->getColor()" size="sm">
                                            {{ $sq->status->getLabel() }}
                                        </x-filament::badge>
                                    </div>
                                </th>
                            @endforeach
                            <th class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 min-w-[120px] border-l border-gray-200 dark:border-gray-700">
                                Selected
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php
                                $currentSelection = $selections[$row['inquiry_item_id']] ?? null;

                                // Find best price for highlighting
                                $bestCost = PHP_INT_MAX;
                                $worstCost = 0;
                                foreach ($row['suppliers'] as $sqData) {
                                    if ($sqData['has_quote']) {
                                        $bestCost = min($bestCost, $sqData['unit_cost']);
                                        $worstCost = max($worstCost, $sqData['unit_cost']);
                                    }
                                }
                                if ($bestCost === PHP_INT_MAX) $bestCost = 0;
                            @endphp
                            <tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 {{ $currentSelection ? 'bg-success-50 dark:bg-success-900/10' : '' }}">
                                <td class="py-3 px-4 font-medium sticky left-0 bg-inherit z-10">
                                    <div class="text-gray-900 dark:text-white">{{ $row['product_name'] }}</div>
                                </td>
                                <td class="text-center py-3 px-3 text-gray-600 dark:text-gray-400">
                                    {{ number_format($row['quantity']) }} {{ $row['unit'] }}
                                </td>
                                <td class="text-center py-3 px-3 text-gray-500 dark:text-gray-400">
                                    @if($row['target_price'])
                                        $ {{ \App\Domain\Infrastructure\Support\Money::format($row['target_price'], 4) }}
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">—</span>
                                    @endif
                                </td>
                                @foreach($supplierQuotations as $sq)
                                    @php
                                        $sqData = $row['suppliers'][$sq->id] ?? null;
                                        $isSelected = $currentSelection && $currentSelection['sq_id'] === $sq->id;
                                        $isBest = $sqData && $sqData['has_quote'] && $sqData['unit_cost'] === $bestCost && $bestCost !== $worstCost;
                                        $isWorst = $sqData && $sqData['has_quote'] && $sqData['unit_cost'] === $worstCost && $bestCost !== $worstCost;
                                    @endphp
                                    <td class="text-center py-3 px-4 border-l border-gray-200 dark:border-gray-700 {{ $isSelected ? 'ring-2 ring-inset ring-success-500 bg-success-50 dark:bg-success-900/20' : '' }}">
                                        @if($sqData && $sqData['has_quote'])
                                            <button
                                                wire:click="selectSupplier({{ $row['inquiry_item_id'] }}, {{ $sq->id }}, {{ $sqData['sq_item_id'] }})"
                                                class="w-full text-center cursor-pointer hover:opacity-80 transition-opacity"
                                            >
                                                <div class="font-semibold {{ $isBest ? 'text-success-600' : ($isWorst ? 'text-danger-600' : 'text-gray-900 dark:text-white') }}">
                                                    $ {{ \App\Domain\Infrastructure\Support\Money::format($sqData['unit_cost'], 4) }}
                                                </div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                                    Total: $ {{ \App\Domain\Infrastructure\Support\Money::format($sqData['total_cost'], 2) }}
                                                </div>
                                                @if($sqData['moq'])
                                                    <div class="text-xs text-gray-400 dark:text-gray-500">
                                                        MOQ: {{ number_format($sqData['moq']) }}
                                                    </div>
                                                @endif
                                                @if($sqData['lead_time_days'])
                                                    <div class="text-xs text-gray-400 dark:text-gray-500">
                                                        {{ $sqData['lead_time_days'] }}d lead
                                                    </div>
                                                @endif
                                                @if($isSelected)
                                                    <div class="mt-1">
                                                        <x-filament::badge color="success" size="sm">
                                                            ✓ Selected
                                                        </x-filament::badge>
                                                    </div>
                                                @endif
                                            </button>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">—</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-center py-3 px-4 border-l border-gray-200 dark:border-gray-700">
                                    @if($currentSelection)
                                        @php
                                            $selectedSq = $supplierQuotations->firstWhere('id', $currentSelection['sq_id']);
                                        @endphp
                                        <div class="text-sm font-medium text-success-600">
                                            {{ $selectedSq?->company?->name ?? '—' }}
                                        </div>
                                        <button
                                            wire:click="clearSelection({{ $row['inquiry_item_id'] }})"
                                            class="text-xs text-danger-500 hover:text-danger-700 mt-1 cursor-pointer"
                                        >
                                            Clear
                                        </button>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-gray-500 italic">Click a price to select</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        {{-- Selection Summary --}}
        @if(!empty($selections))
            <x-filament::section heading="Selection Summary" class="mt-6">
                @php
                    $summaryBySupplier = [];
                    foreach ($selections as $inquiryItemId => $sel) {
                        $sqId = $sel['sq_id'];
                        if (!isset($summaryBySupplier[$sqId])) {
                            $sq = $supplierQuotations->firstWhere('id', $sqId);
                            $summaryBySupplier[$sqId] = [
                                'supplier' => $sq?->company?->name ?? 'Unknown',
                                'reference' => $sq?->reference ?? '—',
                                'items' => 0,
                                'total' => 0,
                            ];
                        }
                        $summaryBySupplier[$sqId]['items']++;

                        // Find the row to get total
                        foreach ($rows as $row) {
                            if ($row['inquiry_item_id'] === $inquiryItemId) {
                                $sqData = $row['suppliers'][$sqId] ?? null;
                                if ($sqData) {
                                    $summaryBySupplier[$sqId]['total'] += $sqData['total_cost'];
                                }
                                break;
                            }
                        }
                    }
                @endphp

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    @foreach($summaryBySupplier as $sqId => $summary)
                        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $summary['supplier'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['reference'] }}</div>
                            <div class="mt-2 flex justify-between">
                                <span class="text-sm text-gray-600 dark:text-gray-400">{{ $summary['items'] }} items</span>
                                <span class="text-sm font-semibold text-gray-900 dark:text-white">
                                    $ {{ \App\Domain\Infrastructure\Support\Money::format($summary['total'], 2) }}
                                </span>
                            </div>
                        </div>
                    @endforeach
                </div>

                @php
                    $grandTotal = collect($summaryBySupplier)->sum('total');
                @endphp
                <div class="mt-4 text-right">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Grand Total:</span>
                    <span class="text-lg font-bold text-gray-900 dark:text-white ml-2">
                        $ {{ \App\Domain\Infrastructure\Support\Money::format($grandTotal, 2) }}
                    </span>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
