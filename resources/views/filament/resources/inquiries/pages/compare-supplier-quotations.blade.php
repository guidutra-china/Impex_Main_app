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
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @php
                $cards = [
                    ['label' => 'Products', 'value' => count($rows), 'color' => 'primary'],
                    ['label' => 'Suppliers Quoting', 'value' => $supplierQuotations->count(), 'color' => 'info'],
                    ['label' => 'Items Selected', 'value' => count($selections), 'color' => 'success'],
                    ['label' => 'Pending Selection', 'value' => count($rows) - count($selections), 'color' => 'warning'],
                ];
            @endphp
            @foreach($cards as $card)
                <div @class([
                    'rounded-xl border p-4 text-center',
                    match ($card['color']) {
                        'primary' => 'border-primary-200 bg-primary-50 dark:border-primary-500/20 dark:bg-primary-500/5',
                        'info' => 'border-info-200 bg-info-50 dark:border-info-500/20 dark:bg-info-500/5',
                        'success' => 'border-success-200 bg-success-50 dark:border-success-500/20 dark:bg-success-500/5',
                        'warning' => 'border-warning-200 bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5',
                        default => 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5',
                    },
                ])>
                    <div @class([
                        'text-2xl font-bold',
                        match ($card['color']) {
                            'primary' => 'text-primary-600 dark:text-primary-400',
                            'info' => 'text-info-600 dark:text-info-400',
                            'success' => 'text-success-600 dark:text-success-400',
                            'warning' => 'text-warning-600 dark:text-warning-400',
                            default => 'text-gray-600 dark:text-gray-400',
                        },
                    ])>{{ $card['value'] }}</div>
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mt-1">{{ $card['label'] }}</div>
                </div>
            @endforeach
        </div>

        {{-- Quick Actions --}}
        <div class="flex flex-wrap gap-2">
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
                            <th class="text-left py-3 px-4 font-semibold text-gray-700 dark:text-gray-300" style="min-width: 200px;">
                                Product
                            </th>
                            <th class="text-center py-3 px-3 font-semibold text-gray-700 dark:text-gray-300" style="min-width: 80px;">
                                Qty
                            </th>
                            <th class="text-center py-3 px-3 font-semibold text-gray-700 dark:text-gray-300" style="min-width: 100px;">
                                Target
                            </th>
                            @foreach($supplierQuotations as $sq)
                                <th class="text-center py-3 px-4 font-semibold border-l border-gray-200 dark:border-gray-700" style="min-width: 180px; color: {{ ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899'][$loop->index % 6] }}">
                                    <div>{{ $sq->company->name }}</div>
                                    <div class="text-xs font-normal text-gray-500 dark:text-gray-400">{{ $sq->reference }}</div>
                                    <div class="text-xs font-normal mt-1">
                                        <x-filament::badge :color="$sq->status->getColor()" size="sm">
                                            {{ $sq->status->getLabel() }}
                                        </x-filament::badge>
                                    </div>
                                </th>
                            @endforeach
                            <th class="text-center py-3 px-4 font-semibold text-gray-700 dark:text-gray-300 border-l border-gray-200 dark:border-gray-700" style="min-width: 120px;">
                                Selected
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $row)
                            @php
                                $currentSelection = $selections[$row['inquiry_item_id']] ?? null;
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
                            <tr class="border-b border-gray-100 dark:border-gray-800" style="{{ $currentSelection ? 'background-color: rgba(16, 185, 129, 0.05);' : '' }}">
                                <td class="py-3 px-4 font-medium text-gray-900 dark:text-white">
                                    {{ $row['product_name'] }}
                                </td>
                                <td class="text-center py-3 px-3 text-gray-600 dark:text-gray-400">
                                    {{ number_format($row['quantity']) }} {{ $row['unit'] }}
                                </td>
                                <td class="text-center py-3 px-3 text-gray-500 dark:text-gray-400">
                                    @if($row['target_price'])
                                        $ {{ \App\Domain\Infrastructure\Support\Money::format($row['target_price'], 4) }}
                                    @else
                                        <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                    @endif
                                </td>
                                @foreach($supplierQuotations as $sq)
                                    @php
                                        $sqData = $row['suppliers'][$sq->id] ?? null;
                                        $isSelected = $currentSelection && $currentSelection['sq_id'] === $sq->id;
                                        $isBest = $sqData && $sqData['has_quote'] && $sqData['unit_cost'] === $bestCost && $bestCost !== $worstCost;
                                        $isWorst = $sqData && $sqData['has_quote'] && $sqData['unit_cost'] === $worstCost && $bestCost !== $worstCost;
                                    @endphp
                                    <td class="text-center py-3 px-4 border-l border-gray-200 dark:border-gray-700" style="{{ $isSelected ? 'outline: 2px solid rgb(16, 185, 129); outline-offset: -2px; background-color: rgba(16, 185, 129, 0.08); border-radius: 4px;' : '' }}">
                                        @if($sqData && $sqData['has_quote'])
                                            <button
                                                wire:click="selectSupplier({{ $row['inquiry_item_id'] }}, {{ $sq->id }}, {{ $sqData['sq_item_id'] }})"
                                                class="w-full text-center cursor-pointer"
                                                style="opacity: 1; transition: opacity 0.15s;"
                                                onmouseover="this.style.opacity='0.8'"
                                                onmouseout="this.style.opacity='1'"
                                            >
                                                <div class="font-semibold" style="color: {{ $isBest ? '#10b981' : ($isWorst ? '#ef4444' : 'inherit') }}">
                                                    $ {{ \App\Domain\Infrastructure\Support\Money::format($sqData['unit_cost'], 4) }}
                                                </div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400" style="margin-top: 2px;">
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
                                                    <div style="margin-top: 4px;">
                                                        <x-filament::badge color="success" size="sm">
                                                            ✓ Selected
                                                        </x-filament::badge>
                                                    </div>
                                                @endif
                                            </button>
                                        @else
                                            <span class="text-gray-300 dark:text-gray-600">&mdash;</span>
                                        @endif
                                    </td>
                                @endforeach
                                <td class="text-center py-3 px-4 border-l border-gray-200 dark:border-gray-700">
                                    @if($currentSelection)
                                        @php
                                            $selectedSq = $supplierQuotations->firstWhere('id', $currentSelection['sq_id']);
                                        @endphp
                                        <div class="text-sm font-medium" style="color: #10b981;">
                                            {{ $selectedSq?->company?->name ?? '—' }}
                                        </div>
                                        <button
                                            wire:click="clearSelection({{ $row['inquiry_item_id'] }})"
                                            class="text-xs cursor-pointer"
                                            style="color: #ef4444; margin-top: 4px;"
                                            onmouseover="this.style.color='#dc2626'"
                                            onmouseout="this.style.color='#ef4444'"
                                        >
                                            Clear
                                        </button>
                                    @else
                                        <span class="text-xs text-gray-400 dark:text-gray-500" style="font-style: italic;">Click a price to select</span>
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
            <x-filament::section heading="Selection Summary">
                @php
                    $summaryBySupplier = [];
                    foreach ($selections as $inquiryItemId => $sel) {
                        $sqId = $sel['sq_id'];
                        if (!isset($summaryBySupplier[$sqId])) {
                            $sq = $supplierQuotations->firstWhere('id', $sqId);
                            $summaryBySupplier[$sqId] = [
                                'supplier' => $sq?->company?->name ?? 'Unknown',
                                'reference' => $sq?->reference ?? '—',
                                'items' => [],
                                'total' => 0,
                            ];
                        }

                        foreach ($rows as $row) {
                            if ($row['inquiry_item_id'] === $inquiryItemId) {
                                $sqData = $row['suppliers'][$sqId] ?? null;
                                if ($sqData) {
                                    $summaryBySupplier[$sqId]['items'][] = [
                                        'product' => $row['product_name'],
                                        'quantity' => $row['quantity'],
                                        'unit' => $row['unit'],
                                        'unit_cost' => $sqData['unit_cost'],
                                        'total_cost' => $sqData['total_cost'],
                                    ];
                                    $summaryBySupplier[$sqId]['total'] += $sqData['total_cost'];
                                }
                                break;
                            }
                        }
                    }
                @endphp

                @foreach($summaryBySupplier as $sqId => $summary)
                    <div class="rounded-xl border border-gray-200 dark:border-gray-700 p-4" style="margin-bottom: 12px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <div>
                                <div class="font-semibold text-gray-900 dark:text-white">{{ $summary['supplier'] }}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $summary['reference'] }}</div>
                            </div>
                            <div class="text-right">
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ count($summary['items']) }} items</div>
                                <div class="font-semibold text-gray-900 dark:text-white">
                                    $ {{ \App\Domain\Infrastructure\Support\Money::format($summary['total'], 2) }}
                                </div>
                            </div>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 dark:border-gray-700">
                                    <th class="text-left py-2 px-2 text-xs font-medium text-gray-500 dark:text-gray-400">Product</th>
                                    <th class="text-center py-2 px-2 text-xs font-medium text-gray-500 dark:text-gray-400">Qty</th>
                                    <th class="text-right py-2 px-2 text-xs font-medium text-gray-500 dark:text-gray-400">Unit Cost</th>
                                    <th class="text-right py-2 px-2 text-xs font-medium text-gray-500 dark:text-gray-400">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($summary['items'] as $item)
                                    <tr class="border-b border-gray-100 dark:border-gray-800">
                                        <td class="py-2 px-2 text-gray-900 dark:text-white">{{ $item['product'] }}</td>
                                        <td class="text-center py-2 px-2 text-gray-600 dark:text-gray-400">{{ number_format($item['quantity']) }} {{ $item['unit'] }}</td>
                                        <td class="text-right py-2 px-2 text-gray-900 dark:text-white">$ {{ \App\Domain\Infrastructure\Support\Money::format($item['unit_cost'], 2) }}</td>
                                        <td class="text-right py-2 px-2 font-medium text-gray-900 dark:text-white">$ {{ \App\Domain\Infrastructure\Support\Money::format($item['total_cost'], 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="border-t border-gray-300 dark:border-gray-600">
                                    <td colspan="3" class="py-2 px-2 text-right text-sm font-semibold text-gray-700 dark:text-gray-300">Supplier Total:</td>
                                    <td class="py-2 px-2 text-right text-sm font-bold text-gray-900 dark:text-white">$ {{ \App\Domain\Infrastructure\Support\Money::format($summary['total'], 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endforeach

                @php
                    $grandTotal = collect($summaryBySupplier)->sum('total');
                @endphp
                <div style="margin-top: 16px; padding-top: 12px; border-top: 2px solid; text-align: right;" class="border-gray-300 dark:border-gray-600">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Grand Total:</span>
                    <span class="text-xl font-bold text-gray-900 dark:text-white" style="margin-left: 8px;">
                        $ {{ \App\Domain\Infrastructure\Support\Money::format($grandTotal, 2) }}
                    </span>
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
