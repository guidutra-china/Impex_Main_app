<div class="px-6 pb-6 space-y-6">
    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header flex items-center gap-3 px-6 py-4">
            <h3 class="fi-section-header-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">
                Production Grid
            </h3>
        </div>
        <div class="fi-section-content px-6 pb-6">
            <livewire:admin.production-actuals-grid
                :schedule="$record"
                :key="'actuals-grid-' . $record->id"
            />
        </div>
    </div>
</div>
