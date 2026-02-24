@extends('pdf.layouts.document')

@section('extra-styles')
    .status-badge {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 6.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .status-paid {
        background: #dcfce7;
        color: #166534;
    }
    .status-pending {
        background: #fef3c7;
        color: #92400e;
    }
    .status-invoiced {
        background: #dbeafe;
        color: #1e40af;
    }
    .status-waived {
        background: #f3f4f6;
        color: #6b7280;
    }
    .exchange-note {
        font-size: 6.5pt;
        color: #9ca3af;
        font-style: italic;
    }
    .summary-box {
        margin-top: 12px;
        padding: 10px 14px;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        background: #f9fafb;
    }
    .summary-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 8.5pt;
    }
    .summary-table td {
        padding: 3px 0;
    }
    .summary-table .label-cell {
        color: #6b7280;
        font-weight: bold;
    }
    .summary-table .value-cell {
        text-align: right;
        font-weight: bold;
    }
    .summary-table .pending-row td {
        color: #dc2626;
        font-size: 9pt;
        padding-top: 5px;
        border-top: 1px solid #d1d5db;
    }
    .summary-table .paid-row td {
        color: #166534;
    }
    .cost-notes {
        font-size: 7pt;
        color: #9ca3af;
        font-style: italic;
    }
    .generated-at {
        margin-top: 20px;
        font-size: 7pt;
        color: #9ca3af;
        text-align: right;
    }
@endsection

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">PI Reference</td>
            <td class="meta-value">{{ $pi['reference'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">PI Date</td>
            <td class="meta-value">{{ $pi['issue_date'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['currency'] }}</td>
            <td class="meta-value">{{ $pi['currency_code'] }}</td>
        </tr>
    </table>
@endsection

@section('client-info')
    <div class="client-section">
        <table style="width: 100%;">
            <tr>
                <td style="width: 50%; vertical-align: top;">
                    <div class="client-box">
                        <div class="client-label">{{ $labels['from'] }}</div>
                        <div class="client-name">{{ $company['name'] }}</div>
                    </div>
                </td>
                <td style="width: 10px;"></td>
                <td style="width: 50%; vertical-align: top;">
                    <div class="client-box">
                        <div class="client-label">{{ $labels['to'] }}</div>
                        <div class="client-name">{{ $client['name'] }}</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>
@endsection

@section('content')
    @if(count($items) === 0)
        <div style="text-align: center; padding: 30px 0; color: #9ca3af; font-size: 10pt;">
            No additional costs billable to client for this Proforma Invoice.
        </div>
    @else
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th style="width: 90px;">Type</th>
                    <th>Description</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th class="text-right" style="width: 100px;">Amount</th>
                    <th class="text-right" style="width: 100px;">Amount ({{ $pi['currency_code'] }})</th>
                    <th class="text-center" style="width: 60px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($items as $item)
                    <tr>
                        <td class="text-center">{{ $item['index'] }}</td>
                        <td>{{ $item['type'] }}</td>
                        <td>
                            {{ $item['description'] }}
                            @if($item['notes'])
                                <br><span class="cost-notes">{{ $item['notes'] }}</span>
                            @endif
                        </td>
                        <td class="text-center">{{ $item['date'] }}</td>
                        <td class="text-right">
                            {{ $item['original_currency'] }} {{ $item['original_amount'] }}
                        </td>
                        <td class="text-right">
                            @if($item['is_same_currency'])
                                {{ $item['document_amount'] }}
                            @else
                                {{ $item['document_amount'] }}
                                <br><span class="exchange-note">Rate: {{ $item['exchange_rate'] }}</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <span class="status-badge status-{{ $item['status_value'] }}">
                                {{ $item['status'] }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- Summary --}}
        <div class="summary-box">
            <table class="summary-table">
                <tr>
                    <td class="label-cell">Total Additional Costs</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['total'] }}</td>
                </tr>
                <tr class="paid-row">
                    <td class="label-cell">Paid</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['paid'] }}</td>
                </tr>
                @if($totals['has_pending'])
                    <tr class="pending-row">
                        <td class="label-cell">Outstanding Balance</td>
                        <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['pending'] }}</td>
                    </tr>
                @endif
            </table>
        </div>
    @endif

    <div class="generated-at">
        Generated on {{ $generated_at }}
    </div>

    @if(! empty($company['bank_details']))
        <div class="section">
            <div class="section-title">{{ $labels['bank_details'] }}</div>
            <div class="section-content">
                {!! nl2br(e($company['bank_details'])) !!}
            </div>
        </div>
    @endif
@endsection
