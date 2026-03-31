@php $record = $getRecord(); @endphp
<livewire:supplier-portal.component-delivery-grid
    :schedule="$record"
    :key="'comp-delivery-' . $record->id"
/>
