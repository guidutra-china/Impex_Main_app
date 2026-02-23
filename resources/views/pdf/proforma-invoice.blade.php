@extends('pdf.layouts.document')

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ $labels['reference'] }}</td>
            <td class="meta-value">{{ $proforma_invoice['reference'] }} (v{{ $document_version }})@if($proforma_invoice['inquiry_reference'])<br><span style="font-size: 6pt; font-weight: normal; color: #9ca3af;">{{ $labels['inquiry_reference'] }}: {{ $proforma_invoice['inquiry_reference'] }}</span>@endif</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['issue_date'] }}</td>
            <td class="meta-value">{{ $proforma_invoice['issue_date'] }}</td>
        </tr>
        @if($proforma_invoice['valid_until'] !== '—')
            <tr>
                <td class="meta-label">{{ $labels['valid_until'] }}</td>
                <td class="meta-value">{{ $proforma_invoice['valid_until'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="meta-label">{{ $labels['currency'] }} @if($proforma_invoice['incoterm'])/ {{ $labels['incoterm'] }}@endif</td>
            <td class="meta-value">{{ $proforma_invoice['currency_code'] }} @if($proforma_invoice['incoterm'])/ {{ $proforma_invoice['incoterm'] }}@endif</td>
        </tr>
    </table>
@endsection

@section('client-info')
    <div class="client-section">
        <div class="client-box">
            <div class="client-label">{{ $labels['to'] }}</div>
            <div class="client-name">{{ $client['name'] }}</div>
            <div class="client-detail">
                @if($client['legal_name'] && $client['legal_name'] !== $client['name'])
                    {{ $client['legal_name'] }}<br>
                @endif
                @if($client['address'] && $client['address'] !== '—'){{ $client['address'] }}<br>@endif
                @if($client['tax_id']){{ $labels['tax_id'] }}: {{ $client['tax_id'] }}<br>@endif
                @if($client['contact_name']){{ $labels['attention'] }}: {{ $client['contact_name'] }}<br>@endif
                @if($client['phone']){{ $labels['phone'] }}: {{ $client['phone'] }}<br>@endif
                @if($client['contact_email'] ?? $client['email'])
                    {{ $labels['email'] }}: {{ $client['contact_email'] ?? $client['email'] }}
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
                    <td class="text-right">{{ $item['unit_price'] }}</td>
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
                <td class="value-cell">{{ $proforma_invoice['currency_code'] }} {{ $totals['grand_total'] }}</td>
            </tr>
        </table>
    </div>

    {{-- === LINKED QUOTATIONS === --}}
    @if($proforma_invoice['linked_quotations'])
        <div class="section">
            <div class="section-title">{{ $labels['linked_quotations'] }}</div>
            <div class="section-content">
                {{ $proforma_invoice['linked_quotations'] }}
            </div>
        </div>
    @endif

    {{-- === PAYMENT TERMS === --}}
    @if($payment_term['name'])
        <div class="section">
            <div class="section-title">{{ $labels['payment_terms'] }}</div>
            <div class="section-content">
                {{ $payment_term['description'] ?? $payment_term['name'] }}
            </div>
        </div>
    @endif

    {{-- === NOTES === --}}
    @if($proforma_invoice['notes'])
        <div class="section">
            <div class="section-title">{{ $labels['notes'] }}</div>
            <div class="section-content">
                {!! nl2br(e($proforma_invoice['notes'])) !!}
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
