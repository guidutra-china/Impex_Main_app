<!DOCTYPE html>
<html lang="{{ $report->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('statements.title') }} — {{ $report->company->name }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; margin: 20px; }
    </style>
</head>
<body>
    @include('statements._content', ['report' => $report])
</body>
</html>
