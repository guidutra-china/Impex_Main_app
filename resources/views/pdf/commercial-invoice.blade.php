@extends('pdf.layouts.document')

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ $labels['reference'] }}</td>
            <td class="meta-value">{{ $shipment['reference'] }} (v{{ $document_version }})</td>
        </tr>
        @if($shipment['pi_references'])
            <tr>
                <td class="meta-label">INV</td>
                <td class="meta-value">{{ $shipment['pi_references'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="meta-label">{{ $labels['date'] }}</td>
            <td class="meta-value">{{ $shipment['date'] }}</td>
        </tr>
        @if($shipment['incoterm'])
            <tr>
                <td class="meta-label">{{ $labels['incoterm'] }}</td>
                <td class="meta-value">{{ $shipment['incoterm'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="meta-label">{{ $labels['currency'] }}</td>
            <td class="meta-value">{{ $shipment['currency_code'] }}</td>
        </tr>
    </table>
@endsection

@section('client-info')
    @if(! empty($import_modality['is_conta_e_ordem']))
        {{-- === CONTA E ORDEM: Show Importer + Notify Party side by side === --}}
        <table style="width: 100%; margin-top: 10px; border-collapse: collapse;">
            <tr>
                <td style="width: 50%; vertical-align: top; padding-right: 10px;">
                    <div class="client-box">
                        <div class="client-label">IMPORTER</div>
                        <div class="client-name">{{ $import_modality['importer']['name'] }}</div>
                        @if($import_modality['importer']['details'])
                            <div class="client-detail">{!! nl2br(e($import_modality['importer']['details'])) !!}</div>
                        @endif
                    </div>
                </td>
                <td style="width: 50%; vertical-align: top; padding-left: 10px;">
                    <div class="client-box">
                        <div class="client-label">NOTIFY PARTY</div>
                        <div class="client-name">{{ $import_modality['notify_party']['name'] }}</div>
                        <div class="client-detail">
                            @if($import_modality['notify_party']['legal_name'] && $import_modality['notify_party']['legal_name'] !== $import_modality['notify_party']['name'])
                                {{ $import_modality['notify_party']['legal_name'] }}<br>
                            @endif
                            @if($import_modality['notify_party']['address'] && $import_modality['notify_party']['address'] !== '—'){{ $import_modality['notify_party']['address'] }}<br>@endif
                            @if($import_modality['notify_party']['tax_id']){{ $labels['tax_id'] }}: {{ $import_modality['notify_party']['tax_id'] }}<br>@endif
                            @if($import_modality['notify_party']['phone']){{ $labels['phone'] }}: {{ $import_modality['notify_party']['phone'] }}<br>@endif
                            @if($import_modality['notify_party']['email']){{ $labels['email'] }}: {{ $import_modality['notify_party']['email'] }}@endif
                        </div>
                    </div>
                </td>
            </tr>
        </table>
    @else
        {{-- === DIRECT IMPORT: Show single client (original behavior) === --}}
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
                    @if($client['phone']){{ $labels['phone'] }}: {{ $client['phone'] }}<br>@endif
                    @if($client['email']){{ $labels['email'] }}: {{ $client['email'] }}@endif
                </div>
            </div>
        </div>
    @endif
@endsection

@section('content')
    {{-- === ITEMS TABLE === --}}
    <table class="items-table">
        <thead>
            <tr>
                <th style="width: 30px;">{{ $labels['item'] }}</th>
                <th style="width: 80px;">MODEL NO.</th>
                <th>PRODUCT</th>
                <th class="text-center" style="width: 55px;">{{ $labels['quantity'] }}</th>
                <th class="text-center" style="width: 45px;">{{ $labels['unit'] }}</th>
                <th class="text-right" style="width: 85px;">UNIT {{ $shipment['currency_code'] }}</th>
                <th class="text-right" style="width: 100px;">TOTAL {{ $shipment['currency_code'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td class="text-center">{{ $item['index'] }}</td>
                    <td>{{ $item['model_no'] }}</td>
                    <td>
                        {{ $item['product_name'] }}
                        @if($item['description'])
                            <br><span style="font-size: 7.5pt; color: #6b7280;">{{ $item['description'] }}</span>
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
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['subtotal'] }}</td>
            </tr>
            @if($totals['freight'])
                <tr>
                    <td class="label-cell">Freight</td>
                    <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['freight'] }}</td>
                </tr>
            @endif
            <tr class="grand-total">
                <td class="label-cell">{{ $labels['grand_total'] }}</td>
                <td class="value-cell">{{ $shipment['currency_code'] }} {{ $totals['grand_total'] }}</td>
            </tr>
        </table>
    </div>

    {{-- === SHIPPING DETAILS === --}}
    @if(count($shipping_details) > 0)
        <div class="section">
            <div class="section-title">Shipping Details</div>
            <div class="section-content">
                <ol style="margin: 0; padding-left: 16px; line-height: 1.8;">
                    @if(isset($shipping_details['delivery_term']))
                        <li>Delivery term: {{ $shipping_details['delivery_term'] }}</li>
                    @endif
                    @if(isset($shipping_details['port_of_loading']))
                        <li>Port of loading: {{ $shipping_details['port_of_loading'] }}</li>
                    @endif
                    @if(isset($shipping_details['port_of_destination']))
                        <li>Port of destination: {{ $shipping_details['port_of_destination'] }}</li>
                    @endif
                    @if(isset($shipping_details['country_of_origin']))
                        <li>Country of origin: {{ $shipping_details['country_of_origin'] }}</li>
                    @endif
                </ol>
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
