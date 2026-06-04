<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <style>
        * { font-family: 'DejaVu Sans', sans-serif; }
        body { font-size: 11px; color: #1f2937; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        .muted { color: #6b7280; }
        .header { border-bottom: 2px solid #0284c7; padding-bottom: 8px; margin-bottom: 12px; }
        .header-table { width: 100%; }
        .header-table td { vertical-align: top; }
        .company-logo img { max-width: 150px; max-height: 48px; margin-bottom: 4px; }
        .company-name { font-size: 13px; font-weight: bold; color: #0369a1; }
        .report-title { text-align: right; }
        .report-title h1 { color: #0f172a; }
        .meta { width: 100%; margin-bottom: 10px; }
        .meta td { padding: 1px 0; vertical-align: top; }
        .meta .label { color: #6b7280; width: 110px; }
        .fx { margin-bottom: 14px; padding: 6px 8px; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 4px; font-size: 10px; }
        .fx .fx-label { font-weight: bold; color: #0369a1; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.items th { background: #f1f5f9; text-align: left; padding: 6px; border-bottom: 1px solid #cbd5e1; font-size: 10px; text-transform: uppercase; color: #475569; }
        table.items td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        table.items tr { page-break-inside: avoid; }
        .right { text-align: right; }
        .receipt-thumb { height: 70px; max-width: 90px; border: 1px solid #e5e7eb; margin: 0 4px 4px 0; }
        .totals { width: 45%; margin-left: 55%; border-collapse: collapse; }
        .totals td { padding: 4px 6px; }
        .totals .grand { border-top: 2px solid #0284c7; font-weight: bold; font-size: 13px; }
        .footer { margin-top: 24px; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>
    @php($t = fn ($k) => __('reports.trip_expense.'.$k))
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 55%;">
                    @if(! empty($company['logo']))
                        <div class="company-logo"><img src="{{ $company['logo'] }}" alt="{{ $company['name'] }}"></div>
                    @endif
                    <div class="company-name">{{ $company['name'] }}</div>
                </td>
                <td class="report-title" style="width: 45%;">
                    <h1>{{ $t('title') }}</h1>
                    <span class="muted">{{ $trip['reference'] }}<br>{{ $t('issued_at') }} {{ $generated_at }}</span>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr><td class="label">{{ $t('trip') }}</td><td>{{ $trip['title'] }}</td>
            <td class="label">{{ $t('company') }}</td><td>{{ $trip['company'] }}</td></tr>
        <tr><td class="label">{{ $t('destination') }}</td><td>{{ $trip['destination'] ?: '—' }}</td>
            <td class="label">{{ $t('traveler') }}</td><td>{{ $trip['traveler'] ?: '—' }}</td></tr>
        <tr><td class="label">{{ $t('period') }}</td><td colspan="3">
            {{ $trip['start_date'] }}@if($trip['end_date']) — {{ $trip['end_date'] }}@endif
        </td></tr>
    </table>

    @if(! empty($fx_rates))
        <div class="fx">
            <span class="fx-label">{{ $t('fx_rates') }}:</span>
            {{ implode('   ·   ', $fx_rates) }}
        </div>
    @endif

    <table class="items">
        <thead>
            <tr>
                <th>{{ $t('col_datetime') }}</th>
                <th>{{ $t('col_category') }}</th>
                <th>{{ $t('col_description') }}</th>
                <th class="right">{{ $t('col_original') }}</th>
                <th class="right">{{ $t('col_amount') }} ({{ $target_currency }})</th>
                <th>{{ $t('col_receipt') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['category'] }}</td>
                    <td>{{ $row['description'] ?: '—' }}</td>
                    <td class="right">{{ $row['source_currency'] }} {{ $row['source_amount'] }}</td>
                    <td class="right">{{ $target_currency }} {{ $row['target_amount'] }}</td>
                    <td>
                        @foreach($row['receipts'] as $receipt)
                            <img class="receipt-thumb" src="{{ $receipt }}" alt="">
                        @endforeach
                        @if(empty($row['receipts']))<span class="muted">—</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">{{ $t('no_expenses') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        @foreach($source_totals as $total)
            <tr>
                <td class="muted">{{ $t('subtotal') }} {{ $total['currency'] }}</td>
                <td class="right">{{ $total['currency'] }} {{ $total['amount'] }}</td>
            </tr>
        @endforeach
        <tr class="grand">
            <td>{{ $t('total_to_charge') }}</td>
            <td class="right">{{ $target_currency }} {{ $grand_total_target }}</td>
        </tr>
    </table>

    <div class="footer">{{ __('reports.trip_expense.footer', ['company' => $company['name']]) }}</div>
</body>
</html>
