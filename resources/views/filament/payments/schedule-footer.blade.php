<td colspan="{{ count($columns) }}" class="px-4 py-4 border-t-2 border-gray-300 dark:border-gray-600">
    <div class="flex items-center gap-x-12 text-base font-bold">
        <span>
            <span class="text-gray-600 dark:text-gray-400">Total Due:</span>
            <span class="text-gray-900 dark:text-gray-100">{{ $currency }} {{ $totalDue }}</span>
        </span>

        @if ($totalCredits > 0)
            <span>
                <span class="text-gray-600 dark:text-gray-400">Credits:</span>
                <span class="text-blue-600">({{ $currency }} {{ $totalCreditsFormatted }})</span>
            </span>
            <span>
                <span class="text-gray-600 dark:text-gray-400">Net Due:</span>
                <span class="text-gray-900 dark:text-gray-100">{{ $currency }} {{ $netDueFormatted }}</span>
            </span>
        @endif

        <span>
            <span class="text-gray-600 dark:text-gray-400">Paid:</span>
            <span class="text-green-600">{{ $currency }} {{ $totalPaidFormatted }}</span>
        </span>

        <span>
            <span class="text-gray-600 dark:text-gray-400">Remaining:</span>
            <span class="{{ $netRemaining > 0 ? 'text-red-600' : 'text-green-600' }}">
                {{ $currency }} {{ $netRemainingFormatted }}
            </span>
        </span>
    </div>
</td>
