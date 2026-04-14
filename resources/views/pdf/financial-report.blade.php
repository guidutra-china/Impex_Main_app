@extends('pdf.layouts.document')

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ __('financial_report.period', [], $locale) }}</td>
            <td class="meta-value">{{ $report->periodFrom->format('Y-m-d') }} → {{ $report->periodTo->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ __('financial_report.generated_at', [], $locale) }}</td>
            <td class="meta-value">{{ $report->generatedAt->format('Y-m-d') }}</td>
        </tr>
    </table>
@endsection

@section('client-info')
    <table class="recipient-table">
        <tr>
            <td class="recipient-label">{{ __('financial_report.company', [], $locale) }}</td>
        </tr>
        <tr>
            <td>
                <strong>{{ $report->company->name }}</strong><br>
                @if($report->company->legal_name && $report->company->legal_name !== $report->company->name)
                    {{ $report->company->legal_name }}<br>
                @endif
                @if($report->company->email)
                    {{ $report->company->email }}<br>
                @endif
                @if($report->company->phone)
                    {{ $report->company->phone }}
                @endif
            </td>
        </tr>
    </table>
@endsection

@section('content')
    @if($report->financialSummary)
        <div style="margin-bottom: 10px;">
            <div class="section-title" style="font-size: 9pt; font-weight: bold; color: #1e40af; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin-bottom: 6px;">
                {{ __('financial_report.financial_summary', [], $locale) }}
            </div>

            <div style="font-weight: 600; font-size: 7pt; margin-bottom: 3px;">{{ __('financial_report.totals_by_currency', [], $locale) }}</div>
            <table class="items-table" style="margin-bottom: 8px;">
                <thead>
                    <tr>
                        <th>{{ __('financial_report.columns.currency', [], $locale) }}</th>
                        <th style="text-align: right;">{{ __('financial_report.invoiced', [], $locale) }}</th>
                        <th style="text-align: right;">{{ __('financial_report.paid', [], $locale) }}</th>
                        <th style="text-align: right;">{{ __('financial_report.open', [], $locale) }}</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($report->financialSummary->totalsByCurrency as $t)
                    <tr>
                        <td>{{ $t->currency }}</td>
                        <td style="text-align: right;">{{ number_format($t->invoiced, 2) }}</td>
                        <td style="text-align: right;">{{ number_format($t->paid, 2) }}</td>
                        <td style="text-align: right; font-weight: 600;">{{ number_format($t->open, 2) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if(! empty($report->financialSummary->agingByCurrency))
                <div style="font-weight: 600; font-size: 7pt; margin-bottom: 3px;">{{ __('financial_report.aging', [], $locale) }}</div>
                <table class="items-table" style="margin-bottom: 8px;">
                    <thead>
                        <tr>
                            <th>{{ __('financial_report.columns.currency', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('financial_report.days_0_30', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('financial_report.days_31_60', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('financial_report.days_61_90', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('financial_report.days_90_plus', [], $locale) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($report->financialSummary->agingByCurrency as $a)
                        <tr>
                            <td>{{ $a->currency }}</td>
                            <td style="text-align: right;">{{ number_format($a->bucket0to30, 2) }}</td>
                            <td style="text-align: right;">{{ number_format($a->bucket31to60, 2) }}</td>
                            <td style="text-align: right;">{{ number_format($a->bucket61to90, 2) }}</td>
                            <td style="text-align: right;">{{ number_format($a->bucket90plus, 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @php($sections = $report->nonEmptySections())
    @php($moneyColumns = ['total','paid','balance','amount','goods','freight','other_costs','total_costs','grand_total','additional_costs','allocated','unallocated','revenue','cost','commission','total_revenue','margin_amount'])

    @forelse($sections as $section)
        <div style="margin-bottom: 10px;">
            <div class="section-title" style="font-size: 9pt; font-weight: bold; color: #1e40af; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin-bottom: 6px;">
                {{ __($section->titleKey, [], $locale) }} ({{ count($section->rows) }})
            </div>
            <table class="items-table">
                <thead>
                    <tr>
                        @foreach($section->columns as $col)
                            <th @if(in_array($col, $moneyColumns)) style="text-align: right;" @endif>
                                {{ __('financial_report.columns.' . $col, [], $locale) }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($section->rows as $row)
                        @php($rowType = $row['_row_type'] ?? 'header')
                        @php($isDetail = $rowType === 'detail')
                        <tr @if($isDetail) style="background: #f9fafb; font-size: 6pt; color: #666;" @endif>
                            @foreach($section->columns as $col)
                                @php($val = $row[$col] ?? null)
                                @php($isMoney = in_array($col, $moneyColumns))
                                <td @if($isMoney) style="text-align: right;" @endif>
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
        </div>
    @empty
        @if(! $report->financialSummary)
            <p style="color: #777; margin-top: 10px;">{{ __('financial_report.no_records', [], $locale) }}</p>
        @endif
    @endforelse
@endsection
