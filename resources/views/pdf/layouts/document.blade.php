<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="UTF-8">
    <title>{{ $title ?? 'Document' }}</title>
    <style>
        /* === Reset === */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 9pt;
            color: #1f2937;
            line-height: 1.5;
        }

        .container {
            padding: 25px 30px;
        }

        /* === Header === */
        .header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 3px solid #1e40af;
        }

        .header-table {
            width: 100%;
        }

        .header-table td {
            vertical-align: top;
        }

        .company-logo img {
            max-width: 160px;
            max-height: 60px;
            margin-bottom: 6px;
        }

        .company-name {
            font-size: 16pt;
            font-weight: bold;
            color: #1e40af;
            margin-bottom: 2px;
        }

        .company-details {
            font-size: 8pt;
            color: #6b7280;
            line-height: 1.6;
        }

        .document-title {
            font-size: 20pt;
            font-weight: bold;
            color: #1e40af;
            text-align: right;
            margin-bottom: 4px;
        }

        .document-meta {
            text-align: right;
            font-size: 9pt;
            color: #4b5563;
        }

        .document-meta .label {
            color: #9ca3af;
            font-size: 8pt;
            text-transform: uppercase;
        }

        .document-meta .value {
            font-weight: bold;
        }

        /* === Parties === */
        .parties {
            margin: 20px 0;
        }

        .parties-table {
            width: 100%;
        }

        .parties-table td {
            vertical-align: top;
            width: 48%;
        }

        .parties-table td:first-child {
            padding-right: 4%;
        }

        .party-box {
            padding: 12px 14px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            background: #f9fafb;
        }

        .party-label {
            font-size: 7pt;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9ca3af;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .party-name {
            font-size: 11pt;
            font-weight: bold;
            color: #111827;
            margin-bottom: 4px;
        }

        .party-detail {
            font-size: 8pt;
            color: #6b7280;
            line-height: 1.6;
        }

        /* === Content (yield) === */
        .content {
            margin: 15px 0;
        }

        /* === Items Table === */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 8.5pt;
        }

        .items-table thead th {
            background: #1e40af;
            color: #ffffff;
            padding: 8px 10px;
            text-align: left;
            font-weight: bold;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .items-table thead th.text-right {
            text-align: right;
        }

        .items-table thead th.text-center {
            text-align: center;
        }

        .items-table tbody td {
            padding: 7px 10px;
            border-bottom: 1px solid #e5e7eb;
        }

        .items-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .text-right { text-align: right; }
        .text-center { text-align: center; }

        /* === Totals === */
        .totals-wrapper {
            margin-top: 10px;
        }

        .totals-table {
            width: 280px;
            float: right;
            border-collapse: collapse;
        }

        .totals-table td {
            padding: 6px 10px;
            font-size: 9pt;
        }

        .totals-table .label-cell {
            text-align: right;
            color: #6b7280;
            font-weight: bold;
        }

        .totals-table .value-cell {
            text-align: right;
            width: 120px;
        }

        .totals-table .grand-total td {
            background: #1e40af;
            color: #ffffff;
            font-weight: bold;
            font-size: 10pt;
            padding: 8px 10px;
        }

        .totals-table .subtotal-row td {
            border-top: 1px solid #d1d5db;
        }

        /* === Sections below table === */
        .section {
            margin-top: 20px;
            clear: both;
        }

        .section-title {
            font-size: 9pt;
            font-weight: bold;
            color: #1e40af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
        }

        .section-content {
            font-size: 8.5pt;
            color: #4b5563;
            line-height: 1.6;
        }

        /* === Payment Terms Table === */
        .payment-terms-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 6px;
            font-size: 8.5pt;
        }

        .payment-terms-table th {
            background: #f3f4f6;
            padding: 5px 8px;
            text-align: left;
            font-weight: bold;
            color: #374151;
            border-bottom: 1px solid #d1d5db;
        }

        .payment-terms-table td {
            padding: 5px 8px;
            border-bottom: 1px solid #e5e7eb;
        }

        /* === Footer === */
        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 2px solid #1e40af;
            font-size: 7.5pt;
            color: #9ca3af;
            text-align: center;
            line-height: 1.6;
        }

        /* === Page break utility === */
        .page-break {
            page-break-after: always;
        }

        /* === DomPDF page numbering === */
        @page {
            margin: 15mm 10mm 20mm 10mm;
        }

        .page-number {
            position: fixed;
            bottom: 0;
            right: 0;
            font-size: 7pt;
            color: #9ca3af;
        }

        .page-number::after {
            content: "{{ $labels['page'] ?? 'Page' }} " counter(page);
        }

        @yield('extra-styles')
    </style>
</head>
<body>
    <div class="page-number"></div>

    <div class="container">
        {{-- === HEADER === --}}
        <div class="header">
            <table class="header-table">
                <tr>
                    <td style="width: 55%;">
                        @if(! empty($company['logo_path']))
                            <div class="company-logo">
                                <img src="{{ $company['logo_path'] }}" alt="{{ $company['name'] }}">
                            </div>
                        @endif
                        <div class="company-name">{{ $company['name'] }}</div>
                        <div class="company-details">
                            @if($company['address']){{ $company['address'] }}<br>@endif
                            @if($company['city'] || $company['state'] || $company['zip_code'])
                                {{ collect([$company['city'], $company['state'], $company['zip_code']])->filter()->implode(', ') }}<br>
                            @endif
                            @if($company['country']){{ $company['country'] }}<br>@endif
                            @if($company['phone']){{ $labels['phone'] ?? 'Phone' }}: {{ $company['phone'] }}<br>@endif
                            @if($company['email']){{ $labels['email'] ?? 'Email' }}: {{ $company['email'] }}<br>@endif
                            @if($company['tax_id']){{ $labels['tax_id'] ?? 'Tax ID' }}: {{ $company['tax_id'] }}@endif
                        </div>
                    </td>
                    <td style="width: 45%;">
                        <div class="document-title">{{ $title }}</div>
                        @yield('document-meta')
                    </td>
                </tr>
            </table>
        </div>

        {{-- === PARTIES === --}}
        @yield('parties')

        {{-- === CONTENT === --}}
        <div class="content">
            @yield('content')
        </div>

        {{-- === FOOTER === --}}
        @if(! empty($company['footer_text']))
            <div class="footer">
                {!! nl2br(e($company['footer_text'])) !!}
            </div>
        @endif
    </div>
</body>
</html>
