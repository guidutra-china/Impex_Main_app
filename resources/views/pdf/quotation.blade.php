@extends('pdf.layouts.document')

@section('document-meta')
    <div class="document-meta">
        <div><span class="label">{{ $labels['reference'] }}</span></div>
        <div class="value">{{ $quotation['reference'] }}</div>

        <div style="margin-top: 6px;"><span class="label">{{ $labels['date'] }}</span></div>
        <div class="value">{{ $quotation['date'] }}</div>

        @if($quotation['valid_until'] !== '—')
            <div style="margin-top: 6px;"><span class="label">{{ $labels['valid_until'] }}</span></div>
            <div class="value">{{ $quotation['valid_until'] }}</div>
        @endif

        <div style="margin-top: 6px;"><span class="label">{{ $labels['currency'] }}</span></div>
        <div class="value">{{ $quotation['currency_code'] }}</div>

        @if($quotation['inquiry_reference'])
            <div style="margin-top: 6px;"><span class="label">Inquiry Ref.</span></div>
            <div class="value">{{ $quotation['inquiry_reference'] }}</div>
        @endif
    </div>
@endsection

@section('parties')
    <div class="parties">
        <table class="parties-table">
            <tr>
                <td>
                    <div class="party-box">
                        <div class="party-label">{{ $labels['from'] }}</div>
                        <div class="party-name">{{ $company['name'] }}</div>
                        <div class="party-detail">
                            @if($company['address']){{ $company['address'] }}<br>@endif
                            @if($company['city'] || $company['state'])
                                {{ collect([$company['city'], $company['state'], $company['zip_code']])->filter()->implode(', ') }}<br>
                            @endif
                            @if($company['country']){{ $company['country'] }}<br>@endif
                            @if($company['phone']){{ $labels['phone'] }}: {{ $company['phone'] }}<br>@endif
                            @if($company['email']){{ $labels['email'] }}: {{ $company['email'] }}@endif
                        </div>
                    </div>
                </td>
                <td>
                    <div class="party-box">
                        <div class="party-label">{{ $labels['to'] }}</div>
                        <div class="party-name">{{ $client['name'] }}</div>
                        <div class="party-detail">
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
                </td>
            </tr>
        </table>
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
                <strong>{{ $payment_term['name'] }}</strong>

                @if(count($payment_term['stages']) > 0)
                    <table class="payment-terms-table">
                        <thead>
                            <tr>
                                <th>{{ $labels['stage'] }}</th>
                                <th>{{ $labels['percentage'] }}</th>
                                <th>{{ $labels['days'] }}</th>
                                <th>{{ $labels['calculation_base'] }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($payment_term['stages'] as $index => $stage)
                                <tr>
                                    <td>{{ $index + 1 }}</td>
                                    <td>{{ $stage['percentage'] }}</td>
                                    <td>{{ $stage['days'] }}</td>
                                    <td>{{ $stage['calculation_base'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
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
