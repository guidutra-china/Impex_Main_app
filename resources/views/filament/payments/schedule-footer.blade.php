<td colspan="{{ count($columns) }}" style="padding: 16px 16px; border-top: 2px solid #d1d5db;">
    <div style="display: flex; align-items: center; gap: 48px; font-size: 15px; font-weight: 700;">
        <span>
            <span style="color: #6b7280;">{{ __('widgets.document_summary.total_due') }}:</span>
            <span style="color: #111827;">{{ $currency }} {{ $totalDue }}</span>
        </span>

        @if ($totalCredits > 0)
            <span>
                <span style="color: #6b7280;">{{ __('widgets.document_summary.credits') }}:</span>
                <span style="color: #2563eb;">({{ $currency }} {{ $totalCreditsFormatted }})</span>
            </span>
            <span>
                <span style="color: #6b7280;">{{ __('widgets.document_summary.net_due') }}:</span>
                <span style="color: #111827;">{{ $currency }} {{ $netDueFormatted }}</span>
            </span>
        @endif

        <span>
            <span style="color: #6b7280;">{{ __('widgets.document_summary.paid') }}:</span>
            <span style="color: #16a34a;">{{ $currency }} {{ $totalPaidFormatted }}</span>
        </span>

        <span>
            <span style="color: #6b7280;">{{ __('widgets.document_summary.remaining') }}:</span>
            <span style="color: {{ $netRemaining > 0 ? '#dc2626' : '#16a34a' }};">{{ $currency }} {{ $netRemainingFormatted }}</span>
        </span>
    </div>
</td>
