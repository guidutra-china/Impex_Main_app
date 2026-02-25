<x-filament-panels::page>
    @php
        $audit = $this->getRecord();
    @endphp

    <div class="space-y-4">
        <div class="flex items-center gap-4 p-4 rounded-lg bg-white dark:bg-gray-800 shadow-sm ring-1 ring-gray-950/5 dark:ring-white/10">
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ $audit->reference }} — {{ $audit->company->name }}
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $audit->audit_type->getLabel() }} | {{ $audit->location ?? 'No location' }} | Scheduled: {{ $audit->scheduled_date->format('Y-m-d') }}
                </p>
            </div>
            <div>
                <x-filament::badge :color="$audit->status->getColor()">
                    {{ $audit->status->getLabel() }}
                </x-filament::badge>
            </div>
        </div>

        <form wire:submit="save">
            {{ $this->form }}
        </form>
    </div>
</x-filament-panels::page>
