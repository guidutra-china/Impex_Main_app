@extends('pdf.layouts.document')

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ __('statements.period', [], $locale) }}</td>
            <td class="meta-value">{{ $report->periodFrom->format('Y-m-d') }} → {{ $report->periodTo->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ __('statements.generated_at', [], $locale) }}</td>
            <td class="meta-value">{{ $report->generatedAt->format('Y-m-d') }}</td>
        </tr>
    </table>
@endsection

@section('document-recipient')
    <table class="recipient-table">
        <tr>
            <td class="recipient-label">{{ __('statements.company', [], $locale) }}</td>
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

@section('document-body')
    @if($report->financialSummary)
        <div style="margin-bottom: 10px;">
            <div class="section-title" style="font-size: 9pt; font-weight: bold; color: #1e40af; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin-bottom: 6px;">
                {{ __('statements.financial_summary', [], $locale) }}
            </div>

            <div style="font-weight: 600; font-size: 7pt; margin-bottom: 3px;">{{ __('statements.totals_by_currency', [], $locale) }}</div>
            <table class="items-table" style="margin-bottom: 8px;">
                <thead>
                    <tr>
                        <th>{{ __('statements.columns.currency', [], $locale) }}</th>
                        <th style="text-align: right;">{{ __('statements.invoiced', [], $locale) }}</th>
                        <th style="text-align: right;">{{ __('statements.paid', [], $locale) }}</th>
                        <th style="text-align: right;">{{ __('statements.open', [], $locale) }}</th>
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
                <div style="font-weight: 600; font-size: 7pt; margin-bottom: 3px;">{{ __('statements.aging', [], $locale) }}</div>
                <table class="items-table" style="margin-bottom: 8px;">
                    <thead>
                        <tr>
                            <th>{{ __('statements.columns.currency', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('statements.days_0_30', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('statements.days_31_60', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('statements.days_61_90', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('statements.days_90_plus', [], $locale) }}</th>
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

            @if(! empty($report->financialSummary->breakdownByDocumentType))
                <div style="font-weight: 600; font-size: 7pt; margin-bottom: 3px;">{{ __('statements.breakdown_by_document_type', [], $locale) }}</div>
                <table class="items-table" style="margin-bottom: 8px;">
                    <thead>
                        <tr>
                            <th>{{ __('statements.columns.currency', [], $locale) }}</th>
                            <th>{{ __('statements.columns.status', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('statements.columns.total', [], $locale) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($report->financialSummary->breakdownByDocumentType as $currency => $types)
                        @foreach($types as $type => $val)
                            <tr>
                                <td>{{ $currency }}</td>
                                <td>{{ __('statements.sections.' . $type, [], $locale) }}</td>
                                <td style="text-align: right;">{{ number_format($val, 2) }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    @php($sections = $report->nonEmptySections())

    @forelse($sections as $section)
        <div style="margin-bottom: 10px;">
            <div class="section-title" style="font-size: 9pt; font-weight: bold; color: #1e40af; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; margin-bottom: 6px;">
                {{ __($section->titleKey, [], $locale) }} ({{ count($section->rows) }})
            </div>
            <table class="items-table">
                <thead>
                    <tr>
                        @foreach($section->columns as $col)
                            <th @if(in_array($col, ['total','paid','balance','items'])) style="text-align: right;" @endif>
                                {{ __('statements.columns.' . $col, [], $locale) }}
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($section->rows as $row)
                        <tr>
                            @foreach($section->columns as $col)
                                @php($val = $row[$col] ?? null)
                                @php($isMoney = in_array($col, ['total','paid','balance']))
                                @php($isCount = $col === 'items')
                                <td @if($isMoney || $isCount) style="text-align: right;" @endif>
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
        </div>
    @empty
        @if(! $report->financialSummary)
            <p style="color: #777; margin-top: 10px;">{{ __('statements.no_records', [], $locale) }}</p>
        @endif
    @endforelse
@endsection
