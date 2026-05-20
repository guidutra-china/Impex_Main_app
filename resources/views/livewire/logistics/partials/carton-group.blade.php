{{--
    Renders cartons for a parent (container or pallet).
    When count > threshold, splits them into content-based subgroups, each with
    its own expand toggle (carton-subgroup partial). Individual cards available
    via expand inside each subgroup.
--}}
@php
    use App\Domain\Logistics\Services\CartonGroupingService;

    $threshold = 10;
    $count = $cartons->count();
    $useSubgroups = $count > $threshold;
@endphp

@if ($useSubgroups)
    @php
        $subgroups = app(CartonGroupingService::class)->groupByContent($cartons);
    @endphp
    <div class="space-y-2">
        @foreach ($subgroups as $signature => $groupCartons)
            @include('livewire.logistics.partials.carton-subgroup', [
                'signature' => $signature,
                'cartons' => $groupCartons,
            ])
        @endforeach
    </div>
@else
    <div class="space-y-2">
        @foreach ($cartons as $carton)
            @include('livewire.logistics.partials.carton-card', ['carton' => $carton, 'nested' => true])
        @endforeach
    </div>
@endif
