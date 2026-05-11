@extends('pdf.layouts.document')

{{-- v2: multi-supplier sub-rows. Force recompile if you see "Undefined array key 0" — run `php artisan view:clear`. --}}

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ $labels['reference'] }}</td>
            <td class="meta-value">{{ $quotation['reference'] }} (v{{ $document_version }})</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['date'] }}</td>
            <td class="meta-value">{{ $quotation['date'] }}</td>
        </tr>
        @if($quotation['valid_until'] !== '—')
            <tr>
                <td class="meta-label">{{ $labels['valid_until'] }}</td>
                <td class="meta-value">{{ $quotation['valid_until'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="meta-label">{{ $labels['currency'] }}</td>
            <td class="meta-value">{{ $quotation['currency_code'] }}</td>
        </tr>
        @if($quotation['inquiry_reference'])
            <tr>
                <td class="meta-label">{{ $labels['inquiry_reference'] ?? 'Inquiry Ref.' }}</td>
                <td class="meta-value">{{ $quotation['inquiry_reference'] }}</td>
            </tr>
        @endif
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
                <th style="width: 28px;">{{ $labels['item'] }}</th>
                @if(! empty($with_images))
                    <th class="text-center" style="width: 50px;">{{ $labels['photo'] ?? 'Photo' }}</th>
                @endif
                <th style="width: 75px;">{{ $labels['product_code'] }}</th>
                <th style="width: 130px;">{{ $labels['description'] }}</th>
                @if($has_suppliers ?? false)
                    <th style="width: 120px;">{{ $labels['supplier'] }}</th>
                    <th class="text-center" style="width: 50px;">{{ $labels['lead_time'] ?? 'Lead Time' }}</th>
                @endif
                <th class="text-center" style="width: 45px;">{{ $labels['quantity'] }}</th>
                <th class="text-center" style="width: 35px;">{{ $labels['unit'] }}</th>
                <th class="text-right" style="width: 75px;">{{ $labels['unit_price'] }}</th>
                <th class="text-right" style="width: 80px;">{{ $labels['line_total'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                @foreach($item['suppliers'] as $supplierIndex => $supplier)
                    @php $isFirstRow = $supplierIndex === 0; @endphp
                    <tr class="{{ $supplier['is_primary'] ? 'primary-supplier-row' : 'alternative-supplier-row' }}">
                        <td class="text-center">@if($isFirstRow){{ $item['index'] }}@endif</td>
                        @if(! empty($with_images))
                            <td class="text-center">
                                @if($isFirstRow && ! empty($item['image']))
                                    <img src="{{ $item['image'] }}" alt="" style="max-width: 50px; max-height: 50px;">
                                @endif
                            </td>
                        @endif
                        <td>@if($isFirstRow){{ $item['product_code'] }}@endif</td>
                        <td>
                            @if($isFirstRow)
                                {{ $item['description'] }}
                                @if($item['incoterm'])
                                    <br><span style="font-size: 7.5pt; color: #6b7280;">{{ $labels['incoterm'] }}: {{ $item['incoterm'] }}</span>
                                @endif
                            @endif
                        </td>
                        @if($has_suppliers ?? false)
                            <td>{{ $supplier['name'] }}</td>
                            <td class="text-center">@if(! empty($supplier['lead_time_days'])){{ $supplier['lead_time_days'] }}d@endif</td>
                        @endif
                        <td class="text-center">@if($supplier['is_primary']){{ number_format($item['quantity']) }}@endif</td>
                        <td class="text-center">@if($supplier['is_primary']){{ $item['unit'] }}@endif</td>
                        <td class="text-right">{{ $supplier['unit_price'] }}</td>
                        <td class="text-right">@if($supplier['is_primary']){{ $item['line_total'] }}@endif</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>

    {{-- === TOTALS === --}}
    <div class="totals-wrapper">
        <table class="totals-table">
            <tr class="subtotal-row">
                <td class="label-cell">{{ $labels['subtotal'] }}</td>
                <td class="value-cell">{{ $quotation['currency_code'] }} {{ $totals['subtotal'] }}</td>
            </tr>
            @if($totals['show_commission'])
                <tr>
                    <td class="label-cell">{{ $labels['commission'] }} ({{ $totals['commission_rate'] }})</td>
                    <td class="value-cell">{{ $quotation['currency_code'] }} {{ $totals['commission_amount'] }}</td>
                </tr>
            @endif
            <tr class="grand-total">
                <td class="label-cell">{{ $labels['grand_total'] }}</td>
                <td class="value-cell">{{ $quotation['currency_code'] }} {{ $totals['grand_total'] }}</td>
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

    {{-- === NOTES === --}}
    @if($quotation['notes'])
        <div class="section">
            <div class="section-title">{{ $labels['notes'] }}</div>
            <div class="section-content">
                {!! nl2br(e($quotation['notes'])) !!}
            </div>
        </div>
    @endif
@endsection
