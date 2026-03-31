<x-filament-widgets::widget>
    <x-filament::section heading="Production Grid">
        @if($this->record)
            <livewire:admin.production-actuals-grid
                :schedule="$this->record"
                :key="'actuals-grid-' . $this->record->id"
            />
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
