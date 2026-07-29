<div class="flex items-center justify-between gap-3 px-3 py-2">
    <span class="text-sm text-gray-700 dark:text-gray-200">
        {{ __('tables.records_per_page') }}
    </span>

    <select
        wire:model.live="recordsPerPage"
        class="fi-select-input rounded-lg border-gray-300 bg-white py-1 pe-8 ps-2 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-gray-900 dark:text-white"
    >
        @foreach (\App\Livewire\Admin\RecordsPerPageSelector::OPTIONS as $option)
            <option value="{{ $option }}">{{ $option }}</option>
        @endforeach
    </select>
</div>
