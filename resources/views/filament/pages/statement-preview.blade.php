<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <form wire:submit.prevent="generate">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('statements.filters.from') }}</label>
                        <input type="date" wire:model="fromDate"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white" />
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('statements.filters.to') }}</label>
                        <input type="date" wire:model="toDate"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white" />
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('statements.filters.status_scope') }}</label>
                        <select wire:model="statusScope"
                            class="fi-select-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white">
                            <option value="all">{{ __('statements.filters.status_all') }}</option>
                            <option value="active">{{ __('statements.filters.status_active') }}</option>
                            <option value="closed">{{ __('statements.filters.status_closed') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('statements.filters.language') }}</label>
                        <select wire:model="locale"
                            class="fi-select-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white">
                            <option value="en">English</option>
                            <option value="pt_BR">Português (Brasil)</option>
                            <option value="zh_CN">中文 (简体)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white mb-2 block">{{ __('statements.filters.sections') }}</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2">
                        @foreach($this->availableSections() as $section)
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" wire:model="sectionToggles.{{ $section }}"
                                    class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm dark:border-white/10 dark:bg-white/5" />
                                {{ __('statements.sections.' . $section) }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <x-filament::button type="submit" size="sm">
                        {{ __('statements.filters.generate') }}
                    </x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="downloadPdf" type="button">
                        {{ __('statements.actions.download_pdf') }}
                    </x-filament::button>
                </div>
            </form>
        </div>

        {{-- Report preview --}}
        @if($this->report)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 overflow-x-auto">
                @include('statements._content', ['report' => $this->report])
            </div>
        @endif
    </div>
</x-filament-panels::page>
