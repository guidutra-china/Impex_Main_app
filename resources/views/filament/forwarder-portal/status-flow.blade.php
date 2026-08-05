{{--
    Status flow shown inside the quick status-change modal on the forwarder
    portal shipment list: previous → current → next, with the next status
    highlighted as the one the submit button will apply.
--}}
<div class="py-2">
    <div class="flex items-center justify-center gap-3">
        <div class="flex flex-col items-center gap-1.5">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('forwarder_portal.shipment.status_flow_previous') }}
            </span>
            @if ($previous)
                <x-filament::badge :color="$previous->getColor()" :icon="$previous->getIcon()" class="opacity-60">
                    {{ $previous->getLabel() }}
                </x-filament::badge>
            @else
                <span class="text-sm text-gray-400 dark:text-gray-500">—</span>
            @endif
        </div>

        <x-filament::icon icon="heroicon-o-arrow-long-right" class="mt-5 h-5 w-5 shrink-0 text-gray-400" />

        <div class="flex flex-col items-center gap-1.5">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('forwarder_portal.shipment.status_flow_current') }}
            </span>
            <x-filament::badge :color="$current->getColor()" :icon="$current->getIcon()" size="lg">
                {{ $current->getLabel() }}
            </x-filament::badge>
        </div>

        <x-filament::icon icon="heroicon-o-arrow-long-right" class="mt-5 h-5 w-5 shrink-0 text-gray-400" />

        <div class="flex flex-col items-center gap-1.5">
            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                {{ __('forwarder_portal.shipment.status_flow_next') }}
            </span>
            @if ($next)
                <x-filament::badge :color="$next->getColor()" :icon="$next->getIcon()" size="lg">
                    {{ $next->getLabel() }}
                </x-filament::badge>
            @else
                <span class="text-sm text-gray-400 dark:text-gray-500">—</span>
            @endif
        </div>
    </div>

    @unless ($next)
        @if ($canRevert ?? false)
            <div class="mt-4 flex flex-col items-center gap-3">
                <p class="text-center text-sm text-gray-500 dark:text-gray-400">
                    {{ __('forwarder_portal.shipment.status_flow_completed') }}
                </p>
                {{ $action->getModalAction('revertToInTransit') }}
            </div>
        @else
            <p class="mt-4 text-center text-sm text-gray-500 dark:text-gray-400">
                {{ __('forwarder_portal.shipment.status_flow_none') }}
            </p>
        @endif
    @endunless
</div>
