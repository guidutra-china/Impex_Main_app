@php $record = $getRecord(); @endphp
<livewire:supplier-portal.production-schedule-grid
    :schedule="$record"
    :key="'ps-grid-' . $record->id"
/>
