{{-- resources/views/livewire/supplier-portal/production-schedule-grid.blade.php --}}
<div wire:key="ps-grid-{{ $schedule->id }}">
    <div class="flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10 rounded-t-lg">
        <div class="flex items-center gap-3">
            <span class="font-bold text-gray-900 dark:text-white">{{ $schedule->reference }}</span>
            <x-filament::badge :color="$schedule->status->getColor()">
                {{ $schedule->status->getLabel() }}
            </x-filament::badge>
            <span class="text-sm text-gray-500">{{ $schedule->proformaInvoice->reference }}</span>
        </div>
        @if($this->canEdit())
            <div class="flex items-center gap-2">
                <input type="date" wire:model="newDateInput"
                       class="text-sm border border-gray-300 dark:border-white/20 rounded-md px-2 py-1 bg-white dark:bg-white/5 text-gray-900 dark:text-white h-8">
                <x-filament::button size="sm" color="gray" wire:click="addDate" icon="heroicon-o-plus">
                    Add Date
                </x-filament::button>
                <x-filament::button size="sm" color="primary" wire:click="submit" wire:loading.attr="disabled" icon="heroicon-o-paper-airplane">
                    Submit for Approval
                </x-filament::button>
            </div>
        @endif
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium text-xs">
                <tr>
                    <th class="px-4 py-2.5 min-w-[160px]">Product</th>
                    <th class="px-3 py-2.5 text-center">PI Qty</th>
                    <th class="px-3 py-2.5 text-center">Balance</th>
                    @foreach($dates as $date)
                        <th class="px-3 py-2.5 text-center min-w-[100px]">
                            <div>{{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</div>
                            <div class="font-normal text-gray-400">{{ \Carbon\Carbon::parse($date)->format('D') }}</div>
                            @if($this->canEdit())
                                <button wire:click="removeDate('{{ $date }}')" class="text-red-400 hover:text-red-600 text-xs mt-0.5">✕</button>
                            @endif
                        </th>
                    @endforeach
                    <th class="px-3 py-2.5 text-center">Total</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach($items as $item)
                    @php
                        $itemKey  = 'item-' . $item['id'];
                        $total    = $totalsPerItem[$itemKey] ?? 0;
                        $balance  = $total - $item['pi_quantity'];
                    @endphp
                    <tr class="text-gray-900 dark:text-white hover:bg-gray-50 dark:hover:bg-white/5">
                        <td class="px-4 py-2.5">
                            <div class="font-medium">{{ $item['name'] }}</div>
                            @if($item['sku'])
                                <div class="text-xs text-gray-400">{{ $item['sku'] }}</div>
                            @endif
                        </td>
                        <td class="px-3 py-2.5 text-center text-gray-500">{{ number_format($item['pi_quantity']) }}</td>
                        <td class="px-3 py-2.5 text-center font-semibold {{ $balance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $balance >= 0 ? '+' : '' }}{{ number_format($balance) }}
                        </td>
                        @foreach($dates as $date)
                            @php
                                $qty     = $quantities[$itemKey][$date] ?? null;
                                $isRisk  = in_array($date, $riskDates[$itemKey] ?? []);
                            @endphp
                            <td class="px-2 py-1.5 text-center">
                                @if($this->canEdit())
                                    <input type="number" min="0" value="{{ $qty ?? '' }}" placeholder="—"
                                        wire:change="updateQuantity({{ $item['id'] }}, '{{ $date }}', $event.target.value)"
                                        class="w-20 text-center text-sm border {{ $isRisk ? 'border-amber-400' : 'border-gray-300 dark:border-white/20' }} rounded-md px-2 py-1 bg-white dark:bg-white/5 text-gray-900 dark:text-white focus:ring-2 focus:ring-primary-500">
                                @else
                                    <span class="{{ !$qty ? 'text-gray-400' : '' }}">{{ $qty ? number_format($qty) : '—' }}</span>
                                @endif
                                @if($isRisk)
                                    <div class="text-xs text-amber-500 mt-0.5">⚠️ parts</div>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-3 py-2.5 text-center font-bold {{ $balance >= 0 ? 'text-primary-600 dark:text-primary-400' : 'text-red-600' }}">
                            {{ number_format($total) }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
            @if(count($dates) > 0)
                <tfoot>
                    <tr class="bg-gray-50 dark:bg-white/5 border-t-2 border-gray-200 dark:border-white/20 text-xs font-semibold text-gray-600 dark:text-gray-400">
                        <td colspan="3" class="px-4 py-2">TOTAL PER DATE</td>
                        @foreach($dates as $date)
                            <td class="px-3 py-2 text-center">{{ number_format($totalsPerDate[$date] ?? 0) }}</td>
                        @endforeach
                        <td class="px-3 py-2 text-center">{{ number_format(array_sum($totalsPerDate)) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

    @if(empty($dates) && $this->canEdit())
        <div class="px-4 py-8 text-center text-gray-400 text-sm">
            No production dates yet. Click <strong>Add Date</strong> to start building the schedule.
        </div>
    @endif
</div>
