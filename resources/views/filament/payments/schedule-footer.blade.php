<td colspan="{{ count($columns) }}" class="px-4 py-3">
    <div class="flex justify-end gap-8 text-sm">
        <div class="text-right">
            <span class="text-gray-500 dark:text-gray-400">Total Due:</span>
            <span class="font-semibold">{{ $currency }} {{ $totalDue }}</span>
        </div>

        @if ($totalCredits > 0)
            <div class="text-right">
                <span class="text-gray-500 dark:text-gray-400">Credits:</span>
                <span class="font-semibold text-info-600">({{ $currency }} {{ $totalCreditsFormatted }})</span>
            </div>
            <div class="text-right">
                <span class="text-gray-500 dark:text-gray-400">Net Due:</span>
                <span class="font-semibold">{{ $currency }} {{ $netDueFormatted }}</span>
            </div>
        @endif

        <div class="text-right">
            <span class="text-gray-500 dark:text-gray-400">Paid:</span>
            <span class="font-semibold text-success-600">{{ $currency }} {{ $totalPaidFormatted }}</span>
        </div>

        <div class="text-right">
            <span class="text-gray-500 dark:text-gray-400">Remaining:</span>
            <span class="font-semibold {{ $netRemaining > 0 ? 'text-warning-600' : 'text-success-600' }}">
                {{ $currency }} {{ $netRemainingFormatted }}
            </span>
        </div>
    </div>
</td>
