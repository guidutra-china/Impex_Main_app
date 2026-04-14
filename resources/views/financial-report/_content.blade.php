<div class="financial-report-content" style="font-size: 13px; color: #222;">
    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
        <div>
            <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 4px 0;">{{ __('financial_report.title') }}</h2>
            <div style="font-weight: 600;">{{ $report->company->name }}</div>
            @if($report->company->email)
                <div style="color: #777; font-size: 12px;">{{ $report->company->email }}</div>
            @endif
        </div>
        <div style="text-align: right; font-size: 12px; color: #555;">
            <div>{{ __('financial_report.period') }}: {{ $report->periodFrom->format('Y-m-d') }} → {{ $report->periodTo->format('Y-m-d') }}</div>
            <div>{{ __('financial_report.generated_at') }}: {{ $report->generatedAt->format('Y-m-d') }}</div>
        </div>
    </div>

    @if($report->financialSummary)
        <h3 style="font-size: 15px; font-weight: 600; margin: 20px 0 8px 0; border-bottom: 1px solid #d1d5db; padding-bottom: 4px;">{{ __('financial_report.financial_summary') }}</h3>

        <div style="font-weight: 600; font-size: 12px; margin-bottom: 4px;">{{ __('financial_report.totals_by_currency') }}</div>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    <th style="text-align: left; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.columns.currency') }}</th>
                    <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.invoiced') }}</th>
                    <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.paid') }}</th>
                    <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.open') }}</th>
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
            <div style="font-weight: 600; font-size: 12px; margin-bottom: 4px;">{{ __('financial_report.aging') }}</div>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
                <thead>
                    <tr style="background: #f3f4f6;">
                        <th style="text-align: left; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.columns.currency') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.days_0_30') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.days_31_60') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.days_61_90') }}</th>
                        <th style="text-align: right; padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">{{ __('financial_report.days_90_plus') }}</th>
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
    @endif

    @php($sections = $report->nonEmptySections())
    @php($moneyColumns = ['total','paid','balance','amount','goods','freight','other_costs','total_costs','grand_total','additional_costs','allocated','unallocated','revenue','cost','commission','total_revenue','margin_amount'])

    @forelse($sections as $section)
        <h3 style="font-size: 15px; font-weight: 600; margin: 20px 0 8px 0; border-bottom: 1px solid #d1d5db; padding-bottom: 4px;">{{ __($section->titleKey) }} ({{ count($section->rows) }})</h3>
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    @foreach($section->columns as $col)
                        <th style="{{ in_array($col, $moneyColumns) ? 'text-align: right;' : 'text-align: left;' }} padding: 6px 8px; font-size: 12px; border-bottom: 2px solid #e5e7eb;">
                            {{ __('financial_report.columns.' . $col) }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($section->rows as $row)
                    @php($rowType = $row['_row_type'] ?? 'header')
                    @php($isDetail = $rowType === 'detail')
                    <tr style="{{ $isDetail ? 'background: #f9fafb; font-size: 11px; color: #555;' : '' }}">
                        @foreach($section->columns as $col)
                            @php($val = $row[$col] ?? null)
                            @php($isMoney = in_array($col, $moneyColumns))
                            <td style="{{ $isMoney ? 'text-align: right;' : '' }} padding: {{ $isDetail ? '3px 8px' : '5px 8px' }}; border-bottom: 1px solid {{ $isDetail ? '#eee' : '#f3f4f6' }}; font-size: {{ $isDetail ? '11px' : '12px' }};{{ $isDetail && $col === 'number' ? ' padding-left: 20px;' : '' }}{{ !$isDetail && $rowType === 'header' ? ' font-weight: 500;' : '' }}">
                                @if($val === null || $val === '')
                                    {{ $isDetail ? '' : '—' }}
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
            <p style="color: #777;">{{ __('financial_report.no_records') }}</p>
        @endif
    @endforelse
</div>
