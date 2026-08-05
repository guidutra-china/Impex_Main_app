@extends('pdf.layouts.document')

@section('extra-styles')
    .section-heading {
        font-size: 9pt;
        font-weight: bold;
        color: #374151;
        margin: 16px 0 6px 0;
        padding-bottom: 3px;
        border-bottom: 1px solid #e5e7eb;
    }
    .section-heading:first-child {
        margin-top: 0;
    }
    .status-badge {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 6.5pt;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .status-paid { background: #dcfce7; color: #166534; }
    .status-pending { background: #fef3c7; color: #92400e; }
    .status-due { background: #dbeafe; color: #1e40af; }
    .status-overdue { background: #fee2e2; color: #b91c1c; }
    .status-waived { background: #f3f4f6; color: #6b7280; }
    .status-issued { background: #dbeafe; color: #1e40af; }
    .status-partially_paid { background: #fef3c7; color: #92400e; }
    .status-draft { background: #f3f4f6; color: #6b7280; }
    .currency-note {
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
    .summary-table td { padding: 3px 0; }
    .summary-table .label-cell { color: #6b7280; font-weight: bold; }
    .summary-table .value-cell { text-align: right; font-weight: bold; }
    .summary-table .outstanding-row td {
        font-size: 10pt;
        font-weight: bold;
        color: #111827;
        padding-top: 6px;
        border-top: 2px solid #374151;
    }
    .summary-table .overdue-row td {
        color: #dc2626;
        font-size: 9pt;
        padding-top: 5px;
        border-top: 1px solid #d1d5db;
    }
    .summary-table .paid-row td { color: #166534; }
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
        <div class="client-box">
            <div class="client-label">{{ $labels['to'] }}</div>
            <div class="client-name">{{ $client['name'] }}</div>
        </div>
    </div>
@endsection

@section('content')
    {{-- === PAYMENT SCHEDULE === --}}
    @if(count($schedule) > 0)
        <div class="section-heading">Payment Schedule</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Installment</th>
                    <th class="text-center" style="width: 65px;">Due Date</th>
                    <th class="text-right" style="width: 80px;">Amount</th>
                    <th class="text-right" style="width: 80px;">Paid</th>
                    <th class="text-right" style="width: 80px;">Credit</th>
                    <th class="text-right" style="width: 80px;">Balance</th>
                    <th class="text-center" style="width: 60px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($schedule as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>{{ $row['label'] }}</td>
                        <td class="text-center">{{ $row['due_date'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                        <td class="text-right">{{ $row['paid'] }}</td>
                        <td class="text-right">{{ $row['credit_applied'] }}</td>
                        <td class="text-right">{{ $row['balance'] }}</td>
                        <td class="text-center">
                            <span class="status-badge status-{{ $row['status_value'] }}">{{ $row['status'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <div style="text-align: center; padding: 30px 0; color: #9ca3af; font-size: 10pt;">
            No payment schedule for this Proforma Invoice.
        </div>
    @endif

    {{-- === PAYMENTS RECEIVED === --}}
    @if(count($payments) > 0)
        <div class="section-heading">Payments Received</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th>Reference</th>
                    <th style="width: 90px;">Method</th>
                    <th>Applied To</th>
                    <th class="text-right" style="width: 90px;">Amount ({{ $pi['currency_code'] }})</th>
                </tr>
            </thead>
            <tbody>
                @foreach($payments as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td class="text-center">{{ $row['date'] }}</td>
                        <td>{{ $row['reference'] }}</td>
                        <td>{{ $row['method'] }}</td>
                        <td>{{ $row['applied_to'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === DEBIT NOTES === --}}
    @if(count($debit_notes) > 0)
        <div class="section-heading">Debit Notes</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th>Reference</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th class="text-center" style="width: 65px;">Due Date</th>
                    <th class="text-right" style="width: 85px;">Total</th>
                    <th class="text-right" style="width: 85px;">Paid</th>
                    <th class="text-right" style="width: 85px;">Balance</th>
                    <th class="text-center" style="width: 70px;">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach($debit_notes as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>
                            {{ $row['reference'] }}
                            @unless($row['in_totals'])
                                <br><span class="currency-note">{{ $row['currency_code'] }} — not included in totals</span>
                            @endunless
                        </td>
                        <td class="text-center">{{ $row['issued_at'] }}</td>
                        <td class="text-center">{{ $row['due_date'] }}</td>
                        <td class="text-right">{{ $row['currency_code'] }} {{ $row['total'] }}</td>
                        <td class="text-right">{{ $row['paid'] }}</td>
                        <td class="text-right">{{ $row['balance'] }}</td>
                        <td class="text-center">
                            <span class="status-badge status-{{ $row['status_value'] }}">{{ $row['status'] }}</span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === CREDITS APPLIED === --}}
    @if(count($credits) > 0)
        <div class="section-heading">Credit Notes Applied</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th class="text-center" style="width: 65px;">Date</th>
                    <th>Credit</th>
                    <th>Applied To</th>
                    <th class="text-right" style="width: 90px;">Amount ({{ $pi['currency_code'] }})</th>
                </tr>
            </thead>
            <tbody>
                @foreach($credits as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td class="text-center">{{ $row['date'] }}</td>
                        <td>{{ $row['credit'] }}</td>
                        <td>{{ $row['applied_to'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === SUMMARY === --}}
    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td class="label-cell">Proforma Invoice Total</td>
                <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['pi_grand_total'] }}</td>
            </tr>
            @if($totals['has_debit_notes'])
                <tr>
                    <td class="label-cell">Debit Notes</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['debit_notes'] }}</td>
                </tr>
            @endif
            <tr class="paid-row">
                <td class="label-cell">Payments Received</td>
                <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['payments_received'] }}</td>
            </tr>
            @if($totals['has_credits'])
                <tr class="paid-row">
                    <td class="label-cell">Credits Applied</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['credits_applied'] }}</td>
                </tr>
            @endif
            <tr class="outstanding-row">
                <td class="label-cell">Outstanding Balance</td>
                <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['outstanding'] }}</td>
            </tr>
            @if($totals['has_overdue'])
                <tr class="overdue-row">
                    <td class="label-cell">Of which Overdue</td>
                    <td class="value-cell">{{ $pi['currency_code'] }} {{ $totals['overdue'] }}</td>
                </tr>
            @endif
        </table>
    </div>

    <div class="generated-at">
        Generated on {{ $generated_at }}
    </div>
@endsection
