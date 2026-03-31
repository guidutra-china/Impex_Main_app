@php $record = $getRecord(); @endphp
<livewire:admin.production-actuals-grid
    :schedule="$record"
    :key="'actuals-' . $record->id"
/>
