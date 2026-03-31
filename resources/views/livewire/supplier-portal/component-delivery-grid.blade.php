<div wire:key="comp-delivery-grid-{{ $schedule->id }}">
    <div class="flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 rounded-t-lg">
        <div class="flex items-center gap-2 font-semibold text-sm text-gray-700 dark:text-gray-200">
            <x-heroicon-o-truck class="w-4 h-4"/>
            Component Deliveries
        </div>
        @if($this->canEdit())
            <x-filament::button size="sm" color="gray" wire:click="$set('showAddDate', true)" icon="heroicon-o-plus">
                Add Delivery Date
            </x-filament::button>
        @endif
    </div>

    @if($showAddDate)
        <div class="flex items-center gap-2 px-4 py-2 bg-blue-50 dark:bg-blue-900/20 border-b border-gray-200 dark:border-white/10">
            <input type="date" wire:model="newDateInput"
                   class="text-sm border border-gray-300 dark:border-white/20 rounded-md px-2 py-1 bg-white dark:bg-white/5 text-gray-900 dark:text-white">
            <x-filament::button size="xs" wire:click="addDateColumn">Add</x-filament::button>
            <x-filament::button size="xs" color="gray" wire:click="$set('showAddDate', false)">Cancel</x-filament::button>
        </div>
    @endif

    @if(count($components) === 0)
        <div class="px-4 py-8 text-center text-gray-400 text-sm">
            No components defined in the product BOM. Add components in the product catalog first.
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                    <tr>
                        <th class="px-4 py-2.5 min-w-[140px]">Component</th>
                        <th class="px-3 py-2.5 text-center">Product</th>
                        <th class="px-3 py-2.5 text-center">Needed</th>
                        @foreach($allDates as $date)
                            <th class="px-3 py-2.5 text-center min-w-[100px]">
                                <div>{{ \Carbon\Carbon::parse($date)->format('d/m') }}</div>
                                <div class="font-normal text-gray-400">{{ \Carbon\Carbon::parse($date)->format('D') }}</div>
                                @if($this->canEdit())
                                    <button wire:click="removeDateColumn('{{ $date }}')"
                                            class="text-red-400 hover:text-red-600 text-xs mt-0.5">✕</button>
                                @endif
                            </th>
                        @endforeach
                        <th class="px-3 py-2.5 text-center">Received</th>
                        <th class="px-3 py-2.5 text-center min-w-[100px]">Progress</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach($components as $comp)
                        <tr class="text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-white/5">
                            <td class="px-4 py-2.5">
                                <div class="font-medium">{{ $comp['name'] }}</div>
                                @if($comp['supplier_name'])
                                    <div class="text-xs text-gray-400">{{ $comp['supplier_name'] }}</div>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-center text-xs text-gray-500">{{ $comp['product_name'] }}</td>
                            <td class="px-3 py-2.5 text-center font-semibold">{{ number_format($comp['quantity_required']) }}</td>
                            @foreach($allDates as $date)
                                @php
                                    $delivery = $deliveryMap[$comp['id']][$date] ?? null;
                                    $isReceived = $delivery?->isReceived() ?? false;
                                @endphp
                                <td class="px-2 py-1.5 text-center {{ $isReceived ? 'bg-green-50 dark:bg-green-900/20' : '' }}">
                                    @if($delivery)
                                        @if($isReceived)
                                            <div class="font-semibold text-green-600 dark:text-green-400">
                                                {{ number_format($delivery->received_qty) }} ✓
                                            </div>
                                            @if($this->canEdit())
                                                <button wire:click="undoReceived({{ $delivery->id }})"
                                                        class="text-xs text-gray-400 hover:text-gray-600">undo</button>
                                            @endif
                                        @else
                                            @if($this->canEdit())
                                                <div class="flex flex-col items-center gap-0.5">
                                                    <input type="number" min="0"
                                                           value="{{ $delivery->expected_qty ?: '' }}"
                                                           placeholder="—"
                                                           wire:change="updateExpectedQty({{ $delivery->id }}, $event.target.value)"
                                                           class="w-16 text-center text-sm border border-gray-300 dark:border-white/20 rounded px-1 py-0.5 bg-white dark:bg-white/5 text-gray-900 dark:text-white">
                                                    @if($delivery->expected_qty > 0)
                                                        <button wire:click="markReceived({{ $delivery->id }})"
                                                                class="text-xs text-primary-600 hover:underline">✓ received</button>
                                                    @endif
                                                </div>
                                            @else
                                                <span class="{{ $delivery->expected_qty ? '' : 'text-gray-400' }}">
                                                    {{ $delivery->expected_qty ? number_format($delivery->expected_qty) : '—' }}
                                                </span>
                                            @endif
                                        @endif
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                            @endforeach
                            <td class="px-3 py-2.5 text-center font-bold {{ $comp['total_received'] >= $comp['quantity_required'] ? 'text-green-600 dark:text-green-400' : '' }}">
                                {{ number_format($comp['total_received']) }}/{{ number_format($comp['quantity_required']) }}
                            </td>
                            <td class="px-3 py-2.5">
                                <div class="flex items-center gap-1.5">
                                    <div class="flex-1 bg-gray-200 dark:bg-white/10 rounded-full h-2 min-w-[50px]">
                                        <div class="h-2 rounded-full {{ $comp['progress_percent'] >= 100 ? 'bg-green-500' : ($comp['progress_percent'] > 0 ? 'bg-blue-500' : 'bg-gray-300') }}"
                                             style="width: {{ $comp['progress_percent'] }}%"></div>
                                    </div>
                                    <span class="text-xs font-medium text-gray-500 w-8 text-right">{{ $comp['progress_percent'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if(count($allDates) === 0 && $this->canEdit())
            <div class="px-4 py-6 text-center text-gray-400 text-sm border-t border-gray-200 dark:border-white/10">
                No delivery dates yet. Click <strong>Add Delivery Date</strong> to schedule component arrivals.
            </div>
        @endif
    @endif
</div>
