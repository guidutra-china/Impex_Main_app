<div class="statement-content" style="font-size: 13px; color: #222;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 4px 0;">{{ __('statements.title') }}</h2>
            <div style="font-weight: 600;">{{ $report->company->name }}</div>
            @if($report->company->email)
                <div style="color: #777; font-size: 12px;">{{ $report->company->email }}</div>
            @endif
        </div>
        <div style="text-align: right; font-size: 12px; color: #555;">
            <div>{{ __('statements.period') }}: {{ $report->periodFrom->format('Y-m-d') }} → {{ $report->periodTo->format('Y-m-d') }}</div>
            <div>{{ __('statements.generated_at') }}: {{ $report->generatedAt->format('Y-m-d') }}</div>
        </div>
    </div>

    @if($report->financialSummary)
        <h3 style="font-size: 15px; font-weight: 600; margin: 20px 0 8px 0; border-bottom: 1px solid #d1d5db; padding-bottom: 4px;">{{ __('statements.financial_summary') }}</h3>

        <div style="font-weight: 600; font-size: 12px; margin-bottom: 4px;">{{ __('statements.totals_by_currency') }}</div>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    <th style="text-align: left; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.columns.currency') }}</th>
                    <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.invoiced') }}</th>
                    <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.paid') }}</th>
                    <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.open') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($report->financialSummary->totalsByCurrency as $t)
                <tr>
                    <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ $t->currency }}</td>
                    <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format($t->invoiced, 2) }}</td>
                    <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format($t->paid, 2) }}</td>
                    <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6; font-weight: 600;">{{ number_format($t->open, 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if(! empty($report->financialSummary->agingByCurrency))
            <div style="font-weight: 600; font-size: 12px; margin-bottom: 4px;">{{ __('statements.aging') }}</div>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
                <thead>
                    <tr style="background: #f3f4f6;">
                        <th style="text-align: left; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.columns.currency') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.days_0_30') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.days_31_60') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.days_61_90') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.days_90_plus') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($report->financialSummary->agingByCurrency as $a)
                    <tr>
                        <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ $a->currency }}</td>
                        <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format($a->bucket0to30, 2) }}</td>
                        <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format($a->bucket31to60, 2) }}</td>
                        <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format($a->bucket61to90, 2) }}</td>
                        <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format($a->bucket90plus, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif

        @if(! empty($report->financialSummary->breakdownByDocumentType))
            <div style="font-weight: 600; font-size: 12px; margin-bottom: 4px;">{{ __('statements.breakdown_by_document_type') }}</div>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
                <thead>
                    <tr style="background: #f3f4f6;">
                        <th style="text-align: left; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.columns.currency') }}</th>
                        <th style="text-align: left; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.columns.status') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('statements.columns.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($report->financialSummary->breakdownByDocumentType as $currency => $types)
                    @foreach($types as $type => $val)
                        <tr>
                            <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ $currency }}</td>
                            <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ __('statements.sections.' . $type) }}</td>
                            <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format($val, 2) }}</td>
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </table>
        @endif
    @endif

    @php($sections = $report->nonEmptySections())

    @forelse($sections as $section)
        <h3 style="font-size: 15px; font-weight: 600; margin: 20px 0 8px 0; border-bottom: 1px solid #d1d5db; padding-bottom: 4px;">{{ __($section->titleKey) }} ({{ count($section->rows) }})</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    @foreach($section->columns as $col)
                        <th style="{{ in_array($col, ['total','paid','balance','amount','goods','freight','items']) ? 'text-align: right;' : 'text-align: left;' }} padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">
                            {{ __('statements.columns.' . $col) }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($section->rows as $row)
                    <tr>
                        @foreach($section->columns as $col)
                            @php($val = $row[$col] ?? null)
                            @php($isMoney = in_array($col, ['total','paid','balance','amount','goods','freight']))
                            @php($isCount = $col === 'items')
                            <td style="{{ ($isMoney || $isCount) ? 'text-align: right;' : '' }} padding: 5px 8px; border-bottom: 1px solid #f3f4f6; font-size: 12px;">
                                @if($val === null || $val === '')
                                    —
                                @elseif($isMoney)
                                    {{ number_format((float) $val, 2) }}
                                @else
                                    {{ $val }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        @if(! $report->financialSummary)
            <p style="color: #777;">{{ __('statements.no_records') }}</p>
        @endif
    @endforelse
</div>
