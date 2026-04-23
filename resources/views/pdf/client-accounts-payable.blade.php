@extends('pdf.layouts.document')

@section('extra-styles')
    @page {
        margin: 20mm 10mm 8mm 10mm;
    }
@endsection

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ __('client_accounts_payable.generated_at', [], $locale) }}</td>
            <td class="meta-value">{{ $report->generatedAt->format('Y-m-d') }}</td>
        </tr>
        @if($report->periodFrom || $report->periodTo)
            <tr>
                <td class="meta-label">{{ __('client_accounts_payable.period', [], $locale) }}</td>
                <td class="meta-value">
                    {{ $report->periodFrom?->format('Y-m-d') ?? '—' }} → {{ $report->periodTo?->format('Y-m-d') ?? '—' }}
                </td>
            </tr>
        @endif
        <tr>
            <td class="meta-label">{{ __('client_accounts_payable.scope', [], $locale) }}</td>
            <td class="meta-value">
                {{ $report->openOnly ? __('client_accounts_payable.scope_open', [], $locale) : __('client_accounts_payable.scope_all', [], $locale) }}
            </td>
        </tr>
    </table>
@endsection

@section('client-info')
    <table class="recipient-table">
        <tr>
            <td class="recipient-label">{{ __('client_accounts_payable.company', [], $locale) }}</td>
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
            </td>
        </tr>
    </table>
@endsection

@section('content')
    @if(empty($report->rows))
        <p style="color: #777; margin-top: 16px;">
            {{ __('client_accounts_payable.no_records', [], $locale) }}
        </p>
    @else
        <table class="items-table">
            <thead>
                <tr>
                    <th>{{ __('client_accounts_payable.columns.document', [], $locale) }}</th>
                    <th>{{ __('client_accounts_payable.columns.client_reference', [], $locale) }}</th>
                    <th>{{ __('client_accounts_payable.columns.installment', [], $locale) }}</th>
                    <th>{{ __('client_accounts_payable.columns.due_date', [], $locale) }}</th>
                    <th style="text-align: right;">{{ __('client_accounts_payable.columns.amount', [], $locale) }}</th>
                    <th style="text-align: right;">{{ __('client_accounts_payable.columns.paid', [], $locale) }}</th>
                    <th style="text-align: right;">{{ __('client_accounts_payable.columns.balance', [], $locale) }}</th>
                    <th>{{ __('client_accounts_payable.columns.currency', [], $locale) }}</th>
                    <th>{{ __('client_accounts_payable.columns.status', [], $locale) }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($report->rows as $row)
                <tr>
                    <td>{{ $row['document'] }}</td>
                    <td>{{ $row['client_reference'] !== '' ? $row['client_reference'] : '—' }}</td>
                    <td>
                        {{ $row['installment'] }}
                        @if(! empty($row['days_overdue']))
                            <span style="color: #dc2626; font-size: 6pt;">
                                ({{ $row['days_overdue'] }} {{ __('client_accounts_payable.days_overdue', [], $locale) }})
                            </span>
                        @endif
                    </td>
                    <td>{{ $row['due_date'] ?? '—' }}</td>
                    <td style="text-align: right;">{{ number_format((float) $row['amount'], 2) }}</td>
                    <td style="text-align: right;">{{ number_format((float) $row['paid'], 2) }}</td>
                    <td style="text-align: right; font-weight: 600;">{{ number_format((float) $row['balance'], 2) }}</td>
                    <td>{{ $row['currency'] }}</td>
                    <td>{{ __('enums.payment_schedule_status.' . $row['status'], [], $locale) ?? $row['status'] }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @if(! empty($report->totals))
            <div style="margin-top: 12px;">
                <div style="font-weight: 600; font-size: 8pt; margin-bottom: 4px;">
                    {{ __('client_accounts_payable.totals_by_currency', [], $locale) }}
                </div>
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>{{ __('client_accounts_payable.columns.currency', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('client_accounts_payable.columns.amount', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('client_accounts_payable.columns.paid', [], $locale) }}</th>
                            <th style="text-align: right;">{{ __('client_accounts_payable.columns.balance', [], $locale) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($report->totals as $t)
                        <tr>
                            <td>{{ $t['currency'] }}</td>
                            <td style="text-align: right;">{{ number_format((float) $t['total'], 2) }}</td>
                            <td style="text-align: right;">{{ number_format((float) $t['paid'], 2) }}</td>
                            <td style="text-align: right; font-weight: 700;">{{ number_format((float) $t['balance'], 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif
@endsection
