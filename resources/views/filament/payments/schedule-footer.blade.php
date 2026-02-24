<td colspan="{{ count($columns) }}" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between text-sm">
        <div class="flex items-center gap-x-10">
            <span>
                <span class="font-bold text-gray-700 dark:text-gray-300">Total Due:</span>
                <span class="font-bold">{{ $currency }} {{ $totalDue }}</span>
            </span>

            @if ($totalCredits > 0)
                <span>
                    <span class="font-bold text-gray-700 dark:text-gray-300">Credits:</span>
                    <span class="font-bold text-info-600">({{ $currency }} {{ $totalCreditsFormatted }})</span>
                </span>
                <span>
                    <span class="font-bold text-gray-700 dark:text-gray-300">Net Due:</span>
                    <span class="font-bold">{{ $currency }} {{ $netDueFormatted }}</span>
                </span>
            @endif

            <span>
                <span class="font-bold text-gray-700 dark:text-gray-300">Paid:</span>
                <span class="font-bold text-success-600">{{ $currency }} {{ $totalPaidFormatted }}</span>
            </span>
        </div>

        <span>
            <span class="font-bold text-gray-700 dark:text-gray-300">Remaining:</span>
            <span class="font-bold text-lg {{ $netRemaining > 0 ? 'text-warning-600' : 'text-success-600' }}">
                {{ $currency }} {{ $netRemainingFormatted }}
            </span>
        </span>
    </div>
</td>
