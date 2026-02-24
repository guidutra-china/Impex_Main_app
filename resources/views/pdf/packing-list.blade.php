@extends('pdf.layouts.document')

@section('extra-styles')
    .packing-meta-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 7.5pt;
        margin-bottom: 6px;
    }

    .packing-meta-table td {
        padding: 2px 6px;
        vertical-align: top;
    }

    .packing-meta-table .label {
        color: #6b7280;
        font-weight: bold;
        text-transform: uppercase;
        font-size: 6.5pt;
        letter-spacing: 0.3px;
        width: 60px;
    }

    .packing-meta-table .value {
        color: #1f2937;
        font-weight: bold;
    }

    .packing-items-table {
        width: 100%;
        border-collapse: collapse;
        margin: 6px 0;
        font-size: 7pt;
    }

    .packing-items-table thead th {
        background: #1e40af;
        color: #ffffff;
        padding: 4px 5px;
        text-align: left;
        font-weight: bold;
        font-size: 6.5pt;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        white-space: nowrap;
    }

    .packing-items-table thead th.text-right {
        text-align: right;
    }

    .packing-items-table thead th.text-center {
        text-align: center;
    }

    .packing-items-table tbody td {
        padding: 3px 5px;
        border-bottom: 1px solid #e5e7eb;
        font-size: 7pt;
        vertical-align: top;
    }

    .packing-items-table tbody tr:nth-child(even) {
        background: #f9fafb;
    }

    .packing-items-table tfoot td {
        padding: 5px 5px;
        font-weight: bold;
        font-size: 7.5pt;
        border-top: 2px solid #1e40af;
        background: #eff6ff;
    }

    .container-header {
        background: #f0f4ff !important;
        border-top: 2px solid #1e40af;
    }

    .container-header td {
        font-weight: bold;
        font-size: 7.5pt;
        color: #1e40af;
        padding: 5px 5px;
    }

    .container-subtotal {
        background: #f8fafc !important;
    }

    .container-subtotal td {
        font-weight: bold;
        font-size: 7pt;
        color: #374151;
        padding: 4px 5px;
        border-top: 1px solid #93c5fd;
        border-bottom: 2px solid #93c5fd;
    }

    .pallet-badge {
        display: inline-block;
        background: #dbeafe;
        color: #1e40af;
        padding: 1px 4px;
        border-radius: 2px;
        font-size: 6pt;
        font-weight: bold;
    }

    .sub-item td {
        border-bottom: 1px dashed #d1d5db;
        color: #374151;
    }
@endsection

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
                @if($client['phone']){{ $labels['phone'] }}: {{ $client['phone'] }}<br>@endif
                @if($client['email']){{ $labels['email'] }}: {{ $client['email'] }}@endif
            </div>
        </div>
    </div>

    {{-- Shipping details --}}
    <table class="packing-meta-table">
        <tr>
            <td class="label">FROM:</td>
            <td class="value">{{ $shipment['origin_port'] ?? '—' }}</td>
            <td class="label">TO:</td>
            <td class="value">{{ $shipment['destination_port'] ?? '—' }}</td>
            @if($shipment['vessel_name'])
                <td class="label">VESSEL:</td>
                <td class="value">{{ $shipment['vessel_name'] }}</td>
            @endif
        </tr>
        @if($shipment['bl_number'])
            <tr>
                <td class="label">B/L:</td>
                <td class="value" colspan="5">{{ $shipment['bl_number'] }}</td>
            </tr>
        @endif
    </table>
@endsection

@section('content')
    <table class="packing-items-table">
        <thead>
            <tr>
                <th style="width: 55px;">PKG NO.</th>
                <th style="width: 70px;">MODEL NO.</th>
                <th>PRODUCT NAME</th>
                <th class="text-center" style="width: 35px;">UNIT</th>
                <th class="text-center" style="width: 50px;">EQUIP QTY</th>
                <th class="text-center" style="width: 45px;">PKG QTY</th>
                <th class="text-right" style="width: 55px;">NW (KG)</th>
                <th class="text-right" style="width: 55px;">GW (KG)</th>
                <th class="text-center" style="width: 90px;">DIMENSIONS (cm)</th>
                <th class="text-right" style="width: 55px;">VOL (m&sup3;)</th>
            </tr>
        </thead>
        <tbody>
            @foreach($container_groups as $group)
                {{-- Container header row --}}
                @if($has_multiple_containers || $group['container_number'])
                    <tr class="container-header">
                        <td colspan="10">
                            @if($group['container_number'])
                                CONTAINER: {{ $group['container_number'] }}
                            @else
                                NO CONTAINER ASSIGNED
                            @endif
                        </td>
                    </tr>
                @endif

                {{-- Item rows --}}
                @foreach($group['lines'] as $line)
                    <tr class="{{ $line['is_sub_item'] ? 'sub-item' : '' }}">
                        <td>
                            @if($line['package_no'])
                                <strong>{{ $line['package_no'] }}</strong>
                            @endif
                            @if($line['pallet'])
                                <br><span class="pallet-badge">{{ $line['pallet'] }}</span>
                            @endif
                        </td>
                        <td>{{ $line['model_no'] }}</td>
                        <td>
                            {{ $line['product_name'] }}
                            @if($line['description'])
                                <br><span style="font-size: 6pt; color: #6b7280;">{{ $line['description'] }}</span>
                            @endif
                        </td>
                        <td class="text-center">{{ $line['unit'] }}</td>
                        <td class="text-center">{{ $line['equipment_qty'] ?: '' }}</td>
                        <td class="text-center">{{ $line['package_qty'] ?: '' }}</td>
                        <td class="text-right">{{ $line['net_weight'] }}</td>
                        <td class="text-right">{{ $line['gross_weight'] }}</td>
                        <td class="text-center">{{ $line['dimensions'] }}</td>
                        <td class="text-right">{{ $line['volume'] }}</td>
                    </tr>
                @endforeach

                {{-- Container subtotal row (only if multiple containers) --}}
                @if($has_multiple_containers)
                    <tr class="container-subtotal">
                        <td colspan="4" class="text-right">
                            Subtotal {{ $group['container_number'] ?? '' }}
                        </td>
                        <td class="text-center">{{ number_format($group['totals']['equipment_qty']) }}</td>
                        <td class="text-center">{{ number_format($group['totals']['packages']) }}</td>
                        <td class="text-right">{{ number_format($group['totals']['net_weight'], 1) }}</td>
                        <td class="text-right">{{ number_format($group['totals']['gross_weight'], 1) }}</td>
                        <td></td>
                        <td class="text-right">{{ number_format($group['totals']['volume'], 2) }}</td>
                    </tr>
                @endif
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="4" class="text-right">GRAND TOTAL</td>
                <td class="text-center">{{ number_format($totals['total_equipment_qty']) }}</td>
                <td class="text-center">{{ number_format($totals['total_packages']) }}</td>
                <td class="text-right">{{ number_format($totals['total_net_weight'], 1) }}</td>
                <td class="text-right">{{ number_format($totals['total_gross_weight'], 1) }}</td>
                <td></td>
                <td class="text-right">{{ number_format($totals['total_volume'], 2) }}</td>
            </tr>
        </tfoot>
    </table>
@endsection
