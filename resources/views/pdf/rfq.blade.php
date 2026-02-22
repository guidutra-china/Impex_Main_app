@extends('pdf.layouts.document')

@section('extra-styles')
    .specs-text {
        font-size: 7.5pt;
        color: #6b7280;
        margin-top: 2px;
        line-height: 1.4;
    }

    .instructions-box {
        background: #eff6ff;
        border: 1px solid #bfdbfe;
        border-radius: 4px;
        padding: 12px 14px;
        margin-top: 16px;
    }

    .instructions-box .section-title {
        color: #1e40af;
        border-bottom: 1px solid #bfdbfe;
    }
@endsection

@section('document-meta')
    <table class="document-meta-table">
        <tr>
            <td class="meta-label">{{ $labels['reference'] }}</td>
            <td class="meta-value">{{ $rfq['reference'] }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['version'] }}</td>
            <td class="meta-value">v{{ $document_version }}</td>
        </tr>
        <tr>
            <td class="meta-label">{{ $labels['requested_date'] }}</td>
            <td class="meta-value">{{ $rfq['requested_date'] }}</td>
        </tr>
        @if($rfq['response_deadline'] !== '—')
            <tr>
                <td class="meta-label">{{ $labels['response_deadline'] }}</td>
                <td class="meta-value">{{ $rfq['response_deadline'] }}</td>
            </tr>
        @endif
        <tr>
            <td class="meta-label">{{ $labels['currency'] }}</td>
            <td class="meta-value">{{ $rfq['currency_code'] }}</td>
        </tr>
        @if($rfq['incoterm'])
            <tr>
                <td class="meta-label">{{ $labels['incoterm'] }}</td>
                <td class="meta-value">{{ $rfq['incoterm'] }}</td>
            </tr>
        @endif
        @if($rfq['inquiry_reference'])
            <tr>
                <td class="meta-label">{{ $labels['inquiry_reference'] }}</td>
                <td class="meta-value">{{ $rfq['inquiry_reference'] }}</td>
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
                <th style="width: 70px;">{{ $labels['product_code'] }}</th>
                <th>{{ $labels['description'] }}</th>
                <th class="text-center" style="width: 55px;">{{ $labels['quantity'] }}</th>
                <th class="text-center" style="width: 45px;">{{ $labels['unit'] }}</th>
                @if(collect($items)->whereNotNull('target_price')->isNotEmpty())
                    <th class="text-right" style="width: 85px;">{{ $labels['target_price'] }}</th>
                    <th class="text-right" style="width: 90px;">{{ $labels['target_total'] }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @php $hasTargetPrices = collect($items)->whereNotNull('target_price')->isNotEmpty(); @endphp
            @foreach($items as $item)
                <tr>
                    <td class="text-center">{{ $item['index'] }}</td>
                    <td>{{ $item['product_code'] }}</td>
                    <td>
                        {{ $item['description'] }}
                        @if($item['specifications'])
                            <div class="specs-text">{{ $item['specifications'] }}</div>
                        @endif
                        @if($item['notes'])
                            <div class="specs-text"><em>{{ $item['notes'] }}</em></div>
                        @endif
                    </td>
                    <td class="text-center">{{ number_format($item['quantity']) }}</td>
                    <td class="text-center">{{ $item['unit'] }}</td>
                    @if($hasTargetPrices)
                        <td class="text-right">{{ $item['target_price'] ?? '—' }}</td>
                        <td class="text-right">{{ $item['target_total'] ?? '—' }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if($total_target_value)
            <tfoot>
                <tr>
                    <td colspan="{{ $hasTargetPrices ? 6 : 4 }}" class="text-right" style="padding-top: 10px; font-weight: bold;">
                        {{ $labels['total_target_value'] }}:
                    </td>
                    <td class="text-right" style="padding-top: 10px; font-weight: bold;">
                        {{ $rfq['currency_code'] }} {{ $total_target_value }}
                    </td>
                </tr>
            </tfoot>
        @endif
    </table>

    {{-- === QUOTATION INSTRUCTIONS === --}}
    @if($instructions)
        <div class="instructions-box">
            <div class="section-title">{{ $labels['quotation_instructions'] }}</div>
            <div class="section-content">
                {!! nl2br(e($instructions)) !!}
            </div>
        </div>
    @endif

    {{-- === NOTES === --}}
    @if($rfq['notes'])
        <div class="section">
            <div class="section-title">{{ $labels['notes'] }}</div>
            <div class="section-content">
                {!! nl2br(e($rfq['notes'])) !!}
            </div>
        </div>
    @endif

    {{-- === CONTACT FOR QUESTIONS === --}}
    <div class="section" style="margin-top: 20px;">
        <div class="section-content" style="text-align: center; color: #6b7280;">
            For any questions or clarifications, please contact us at
            <strong>{{ $company['email'] }}</strong>
            @if($company['phone'])
                | {{ $company['phone'] }}
            @endif
        </div>
    </div>
@endsection
