@php
    $logoUrl = \App\Providers\Filament\AdminPanelProvider::logoUrl();
@endphp

@if($logoUrl)
    <img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" class="h-full w-auto max-h-12">
@else
    <span class="text-xl font-bold">{{ config('app.name') }}</span>
@endif
