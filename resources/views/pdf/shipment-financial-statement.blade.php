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
    .section-heading:first-child { margin-top: 0; }
    .currency-note { font-size: 6.5pt; color: #9ca3af; font-style: italic; }
    .prorated-note { font-size: 6.5pt; color: #6b7280; font-style: italic; }
    .logistics-table { width: 100%; border-collapse: collapse; font-size: 7.5pt; margin-bottom: 4px; }
    .logistics-table td { padding: 2px 6px 2px 0; vertical-align: top; }
    .logistics-table .k { color: #6b7280; width: 70px; }
    .logistics-table .v { color: #111827; font-weight: bold; }
    .subtotal-row td { font-weight: bold; border-top: 1px solid #d1d5db; padding-top: 4px; }
    .summary-box {
        margin-top: 12px;
        padding: 10px 14px;
        border: 1px solid #e5e7eb;
        border-radius: 4px;
        background: #f9fafb;
    }
    .summary-table { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
    .summary-table td { padding: 3px 0; }
    .summary-table .label-cell { color: #6b7280; font-weight: bold; }
    .summary-table .value-cell { text-align: right; font-weight: bold; }
    .summary-table .paid-row td { color: #166534; }
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
    .mismatch-note {
        margin-top: 6px;
        font-size: 7pt;
        color: #92400e;
        font-style: italic;
    }
    .generated-at { margin-top: 20px; font-size: 7pt; color: #9ca3af; text-align: right; }
@endsection

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ $labels['reference'] }}</td>
            <td class="meta-value">{{ $shipment['reference'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['date'] }}</td>
            <td class="meta-value">{{ $shipment['issue_date'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['currency'] }}</td>
            <td class="meta-value">{{ $shipment['currency_code'] }}</td>
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
    {{-- === SHIPMENT === --}}
    <div class="section-heading">Shipment</div>
    <table class="logistics-table">
        <tr>
            <td class="k">B/L — AWB</td><td class="v">{{ $shipment['bl_number'] }}</td>
            <td class="k">Vessel / Flight</td><td class="v">{{ $shipment['vessel'] }} {{ $shipment['voyage'] }}</td>
            <td class="k">Incoterm</td><td class="v">{{ $shipment['incoterm'] }}</td>
        </tr>
        <tr>
            <td class="k">From</td><td class="v">{{ $shipment['origin_port'] }}</td>
            <td class="k">To</td><td class="v">{{ $shipment['destination_port'] }}</td>
            <td class="k">Mode</td><td class="v">{{ $shipment['transport_mode'] }}</td>
        </tr>
        <tr>
            <td class="k">ETD</td><td class="v">{{ $shipment['etd'] }}</td>
            <td class="k">ETA</td><td class="v">{{ $shipment['eta'] }}</td>
            <td class="k">Client Ref</td><td class="v">{{ $shipment['client_reference'] }}</td>
        </tr>
        <tr>
            <td class="k">Packages</td><td class="v">{{ $shipment['packages'] }}</td>
            <td class="k">Gross Weight</td><td class="v">{{ $shipment['gross_weight'] }}</td>
            <td class="k">Volume</td><td class="v">{{ $shipment['volume'] }}</td>
        </tr>
    </table>

    {{-- === GOODS === --}}
    @if(count($goods) > 0)
        <div class="section-heading">Shipped Goods</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th style="width: 130px;">Proforma Invoice</th>
                    <th>Your Reference</th>
                    <th class="text-right" style="width: 110px;">Shipped Value</th>
                </tr>
            </thead>
            <tbody>
                @foreach($goods as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>{{ $row['reference'] }}</td>
                        <td>
                            {{ $row['client_reference'] }}
                            @unless($row['in_totals'])
                                <br><span class="currency-note">{{ $row['currency_code'] }} — not included in totals</span>
                            @endunless
                        </td>
                        <td class="text-right">{{ $row['currency_code'] }} {{ $row['amount'] }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td colspan="3" class="text-right">Goods Subtotal</td>
                    <td class="text-right">{{ $shipment['currency_code'] }} {{ $goods_total }}</td>
                </tr>
            </tbody>
        </table>
    @endif

    {{-- === CHARGES === --}}
    @if(count($costs) > 0)
        <div class="section-heading">Charges</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 25px;">#</th>
                    <th style="width: 95px;">Type</th>
                    <th>Description</th>
                    <th style="width: 90px;">Document</th>
                    <th class="text-center" style="width: 60px;">Date</th>
                    <th class="text-right" style="width: 95px;">Full Amount</th>
                    <th class="text-right" style="width: 95px;">This Shipment</th>
                </tr>
            </thead>
            <tbody>
                @foreach($costs as $row)
                    <tr>
                        <td class="text-center">{{ $row['index'] }}</td>
                        <td>{{ $row['type'] }}</td>
                        <td>
                            {{ $row['description'] }}
                            @if($row['is_prorated'])
                                <br><span class="prorated-note">{{ $row['share_percent'] }}% of the document charge</span>
                            @endif
                            @unless($row['in_totals'])
                                <br><span class="currency-note">{{ $row['currency_code'] }} — not included in totals</span>
                            @endunless
                        </td>
                        <td>{{ $row['document'] }}</td>
                        <td class="text-center">{{ $row['date'] }}</td>
                        <td class="text-right">{{ $row['currency_code'] }} {{ $row['document_amount'] }}</td>
                        <td class="text-right">{{ $row['currency_code'] }} {{ $row['shipment_amount'] }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal-row">
                    <td colspan="6" class="text-right">Charges Subtotal</td>
                    <td class="text-right">{{ $shipment['currency_code'] }} {{ $costs_total }}</td>
                </tr>
            </tbody>
        </table>
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
                    <th class="text-right" style="width: 90px;">Amount ({{ $shipment['currency_code'] }})</th>
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

    {{-- === SUMMARY BY STAGE === --}}
    @if(count($summary_by_condition) > 0)
        <div class="section-heading">Summary by Stage</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th>Stage</th>
                    <th class="text-right" style="width: 110px;">Amount</th>
                    <th class="text-right" style="width: 110px;">Paid</th>
                    <th class="text-right" style="width: 110px;">Balance</th>
                </tr>
            </thead>
            <tbody>
                @foreach($summary_by_condition as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="text-right">{{ $row['amount'] }}</td>
                        <td class="text-right">{{ $row['paid'] }}</td>
                        <td class="text-right">{{ $row['balance'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- === TOTALS === --}}
    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td class="label-cell">Billed for this shipment</td>
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['billed'] }}</td>
            </tr>
            @if($totals['has_mismatch'])
                <tr>
                    <td class="label-cell">Covered by payment schedule</td>
                    <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['scheduled'] }}</td>
                </tr>
            @endif
            <tr class="paid-row">
                <td class="label-cell">Payments Received</td>
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['paid'] }}</td>
            </tr>
            <tr class="outstanding-row">
                <td class="label-cell">Outstanding Balance</td>
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['outstanding'] }}</td>
            </tr>
            @if($totals['has_overdue'])
                <tr class="overdue-row">
                    <td class="label-cell">Of which Overdue</td>
                    <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['overdue'] }}</td>
                </tr>
            @endif
        </table>
        @if($totals['has_mismatch'])
            <div class="mismatch-note">
                The payment schedule does not cover the full billed amount of this shipment.
            </div>
        @endif
    </div>

    <div class="generated-at">
        Generated on {{ $generated_at }}
    </div>
@endsection
