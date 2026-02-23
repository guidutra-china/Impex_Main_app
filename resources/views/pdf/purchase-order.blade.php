@extends('pdf.layouts.document')

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ $labels['reference'] }}</td>
            <td class="meta-value">{{ $purchase_order['reference'] }} (v{{ $document_version }})</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['issue_date'] }}</td>
            <td class="meta-value">{{ $purchase_order['issue_date'] }}</td>
        </tr>
        @if($purchase_order['expected_delivery_date'] !== '—')
            <tr>
                <td class="meta-label">{{ $labels['expected_delivery'] }}</td>
                <td class="meta-value">{{ $purchase_order['expected_delivery_date'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="meta-label">{{ $labels['currency'] }} @if($purchase_order['incoterm'])/ {{ $labels['incoterm'] }}@endif</td>
            <td class="meta-value">{{ $purchase_order['currency_code'] }} @if($purchase_order['incoterm'])/ {{ $purchase_order['incoterm'] }}@endif</td>
        </tr>
        @if($purchase_order['pi_reference'])
            <tr>
                <td class="meta-label">{{ $labels['pi_reference'] }}</td>
                <td class="meta-value">{{ $purchase_order['pi_reference'] }}</td>
            </tr>
        @endif
    </table>
@endsection

@section('client-info')
    <div class="client-section">
        <div class="client-box">
            <div class="client-label">{{ $labels['to'] }}: {{ $labels['supplier'] }}</div>
            <div class="client-name">{{ $supplier['name'] }}</div>
            <div class="client-detail">
                @if($supplier['legal_name'] && $supplier['legal_name'] !== $supplier['name'])
                    {{ $supplier['legal_name'] }}<br>
                @endif
                @if($supplier['address'] && $supplier['address'] !== '—'){{ $supplier['address'] }}<br>@endif
                @if($supplier['tax_id']){{ $labels['tax_id'] }}: {{ $supplier['tax_id'] }}<br>@endif
                @if($supplier['contact_name']){{ $labels['attention'] }}: {{ $supplier['contact_name'] }}<br>@endif
                @if($supplier['phone']){{ $labels['phone'] }}: {{ $supplier['phone'] }}<br>@endif
                @if($supplier['contact_email'] ?? $supplier['email'])
                    {{ $labels['email'] }}: {{ $supplier['contact_email'] ?? $supplier['email'] }}
                @endif
            </div>
        </div>
    </div>
@endsection

@section('content')
    {{-- === ITEMS TABLE === --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px;">{{ $labels['item'] }}</th>
                <th style="width: 80px;">{{ $labels['product_code'] }}</th>
                <th>{{ $labels['description'] }}</th>
                <th class="text-center" style="width: 55px;">{{ $labels['quantity'] }}</th>
                <th class="text-center" style="width: 45px;">{{ $labels['unit'] }}</th>
                <th class="text-right" style="width: 85px;">{{ $labels['unit_price'] }}</th>
                <th class="text-right" style="width: 90px;">{{ $labels['line_total'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td class="text-center">{{ $item['index'] }}</td>
                    <td>{{ $item['product_code'] }}</td>
                    <td>
                        {{ $item['description'] }}
                        @if($item['specifications'])
                            <br><span style="font-size: 7.5pt; color: #6b7280;">{{ $item['specifications'] }}</span>
                        @endif
                        @if($item['incoterm'])
                            <br><span style="font-size: 7.5pt; color: #6b7280;">{{ $labels['incoterm'] }}: {{ $item['incoterm'] }}</span>
                        @endif
                    </td>
                    <td class="text-center">{{ number_format($item['quantity']) }}</td>
                    <td class="text-center">{{ $item['unit'] }}</td>
                    <td class="text-right">{{ $item['unit_cost'] }}</td>
                    <td class="text-right">{{ $item['line_total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- === TOTALS === --}}
    <div class="totals-wrapper">
        <table class="totals-table">
            <tr class="grand-total">
                <td class="label-cell">{{ $labels['grand_total'] }}</td>
                <td class="value-cell">{{ $purchase_order['currency_code'] }} {{ $totals['grand_total'] }}</td>
            </tr>
        </table>
    </div>

    {{-- === PAYMENT TERMS === --}}
    @if($payment_term['name'])
        <div class="section">
            <div class="section-title">{{ $labels['payment_terms'] }}</div>
            <div class="section-content">
                {{ $payment_term['description'] ?? $payment_term['name'] }}
            </div>
        </div>
    @endif

    {{-- === SHIPPING INSTRUCTIONS === --}}
    @if($purchase_order['shipping_instructions'])
        <div class="section">
            <div class="section-title">{{ $labels['shipping_instructions'] }}</div>
            <div class="section-content">
                {!! nl2br(e($purchase_order['shipping_instructions'])) !!}
            </div>
        </div>
    @endif

    {{-- === NOTES === --}}
    @if($purchase_order['notes'])
        <div class="section">
            <div class="section-title">{{ $labels['notes'] }}</div>
            <div class="section-content">
                {!! nl2br(e($purchase_order['notes'])) !!}
            </div>
        </div>
    @endif

    {{-- === BANK DETAILS === --}}
    @if(! empty($company['bank_details']))
        <div class="section">
            <div class="section-title">{{ $labels['bank_details'] }}</div>
            <div class="section-content">
                {!! nl2br(e($company['bank_details'])) !!}
            </div>
        </div>
    @endif
@endsection
