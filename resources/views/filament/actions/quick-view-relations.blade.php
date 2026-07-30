@php
    /** @var \Illuminate\Database\Eloquent\Model $record */
    /** @var array<class-string<\Filament\Resources\RelationManagers\RelationManager>> $relationManagers */
    /** @var class-string<\Filament\Resources\Pages\ViewRecord> $pageClass */
    $managers = collect($relationManagers)
        ->filter(fn (string $manager): bool => $manager::canViewForRecord($record, $pageClass))
        ->values();
@endphp

@if ($managers->isNotEmpty())
    <div x-data="{ quickViewTab: 0 }" class="flex flex-col gap-y-4">
        <x-filament::tabs>
            @foreach ($managers as $index => $manager)
                <x-filament::tabs.item
                    :alpine-active="'quickViewTab === ' . $index"
                    x-on:click="quickViewTab = {{ $index }}"
                >
                    {{ $manager::getTitle($record, $pageClass) }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        @foreach ($managers as $index => $manager)
            <div x-show="quickViewTab === {{ $index }}" @if ($index > 0) x-cloak @endif>
                @livewire($manager, ['ownerRecord' => $record, 'pageClass' => $pageClass], key('quick-view-relation-' . $record->getKey() . '-' . $index))
            </div>
        @endforeach
    </div>
@endif
