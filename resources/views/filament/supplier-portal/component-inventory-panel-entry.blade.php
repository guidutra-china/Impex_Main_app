@php $record = $getRecord(); @endphp
<livewire:supplier-portal.component-inventory-panel
    :schedule="$record"
    :key="'ps-components-' . $record->id"
/>
