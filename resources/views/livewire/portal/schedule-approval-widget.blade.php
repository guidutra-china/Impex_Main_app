<div wire:key="approval-{{ $schedule->id }}" class="space-y-4">

    @if($isPending)
        <div class="flex items-center gap-3 px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
            <x-heroicon-o-clock class="w-5 h-5 text-amber-500 shrink-0"/>
            <div class="flex-1">
                <p class="font-semibold text-amber-800 dark:text-amber-200 text-sm">
                    Production schedule awaiting your approval
                </p>
                @if($schedule->submitted_at)
                    <p class="text-xs text-amber-600 dark:text-amber-400">
                        Submitted {{ $schedule->submitted_at->format('d/m/Y H:i') }}
                    </p>
                @endif
            </div>
        </div>
    @elseif($schedule->status->value === 'approved')
        <div class="flex items-center gap-2 px-4 py-2 bg-green-50 dark:bg-green-900/20 border border-green-200 rounded-lg text-sm text-green-700 dark:text-green-300">
            <x-heroicon-o-check-circle class="w-4 h-4"/>
            Approved on {{ $schedule->approved_at?->format('d/m/Y') }}
        </div>
    @elseif($schedule->status->value === 'rejected')
        <div class="px-4 py-3 bg-red-50 dark:bg-red-900/20 border border-red-200 rounded-lg text-sm text-red-700 dark:text-red-300">
            <div class="flex items-center gap-2 font-semibold">
                <x-heroicon-o-x-circle class="w-4 h-4"/> Rejected
            </div>
            @if($schedule->approval_notes)
                <p class="mt-1 text-xs">Note: {{ $schedule->approval_notes }}</p>
            @endif
        </div>
    @endif

    @if(count($allDates) > 0)
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 text-xs font-medium">
                    <tr>
                        <th class="px-4 py-2.5 text-left min-w-[140px]">Product</th>
                        <th class="px-3 py-2.5 text-center">PI Qty</th>
                        @foreach($allDates as $date)
                            <th class="px-3 py-2.5 text-center min-w-[80px]">
                                {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}
                            </th>
                        @endforeach
                        <th class="px-3 py-2.5 text-center">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach($items as $item)
                        @php $diff = $item['total'] - $item['pi_quantity']; @endphp
                        <tr class="text-gray-900 dark:text-white">
                            <td class="px-4 py-2.5 font-medium">{{ $item['name'] }}</td>
                            <td class="px-3 py-2.5 text-center text-gray-500">{{ number_format($item['pi_quantity']) }}</td>
                            @foreach($allDates as $date)
                                <td class="px-3 py-2.5 text-center">
                                    {{ isset($item['quantities'][$date]) ? number_format($item['quantities'][$date]) : '—' }}
                                </td>
                            @endforeach
                            <td class="px-3 py-2.5 text-center font-bold {{ $diff >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600' }}">
                                {{ number_format($item['total']) }}
                                @if($diff < 0) <span class="text-xs">(short {{ abs($diff) }})</span> @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    @if($isPending)
        <div class="space-y-3 p-4 bg-gray-50 dark:bg-white/5 rounded-lg border border-gray-200 dark:border-white/10">
            <div>
                <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">
                    Note (required if rejecting)
                </label>
                <textarea
                    wire:model="approvalNote"
                    rows="2"
                    placeholder="Add a note for the supplier..."
                    class="w-full text-sm border border-gray-300 dark:border-white/20 rounded-lg px-3 py-2 bg-white dark:bg-white/5 text-gray-900 dark:text-white resize-none focus:ring-2 focus:ring-primary-500">
                </textarea>
                @error('approvalNote')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>
            <div class="flex gap-3">
                <x-filament::button
                    wire:click="approve"
                    wire:loading.attr="disabled"
                    color="success"
                    icon="heroicon-o-check-circle"
                    class="flex-1">
                    Approve Schedule
                </x-filament::button>
                <x-filament::button
                    wire:click="reject"
                    wire:loading.attr="disabled"
                    color="danger"
                    icon="heroicon-o-x-circle"
                    class="flex-1">
                    Reject
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
