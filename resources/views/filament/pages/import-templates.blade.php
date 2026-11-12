@use('App\Filament\Pages\ImportTemplates')
<x-filament-panels::page>
    <div class="space-y-6">
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <p class="text-sm text-gray-700 dark:text-gray-300">{{ __('import_templates.intro') }}</p>
            <ul class="mt-3 list-disc ps-5 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                @foreach (__('import_templates.rules') as $rule)
                    <li>{{ $rule }}</li>
                @endforeach
            </ul>
        </div>

        @foreach (ImportTemplates::EXAMPLES as $key => $file)
            <div wire:key="import-template-{{ $key }}" class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div class="space-y-2 min-w-0">
                        <h3 class="text-base font-semibold text-gray-950 dark:text-white">
                            {{ __("import_templates.items.{$key}.title") }}
                        </h3>
                        <p class="text-sm text-gray-700 dark:text-gray-300">{{ __("import_templates.items.{$key}.why") }}</p>
                        <p class="text-sm">
                            <span class="font-medium text-gray-950 dark:text-white">{{ __('import_templates.where') }}:</span>
                            <span class="text-gray-700 dark:text-gray-300">{{ __("import_templates.items.{$key}.where") }}</span>
                        </p>
                        <ul class="list-disc ps-5 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                            @foreach (__("import_templates.items.{$key}.how") as $step)
                                <li>{{ $step }}</li>
                            @endforeach
                        </ul>
                    </div>

                    <div class="flex shrink-0 flex-col gap-2 lg:w-64">
                        <x-filament::button
                            wire:click="downloadExample('{{ $key }}')"
                            icon="heroicon-o-arrow-down-tray"
                            size="sm"
                        >
                            {{ __('import_templates.download_example') }}
                        </x-filament::button>

                        @if (array_key_exists($key, ImportTemplates::BLANK_TEMPLATES))
                            <x-filament::button
                                wire:click="downloadBlank('{{ $key }}', false)"
                                icon="heroicon-o-document"
                                color="gray"
                                size="sm"
                            >
                                {{ __('import_templates.download_blank') }}
                            </x-filament::button>
                            <x-filament::button
                                wire:click="downloadBlank('{{ $key }}', true)"
                                icon="heroicon-o-document-duplicate"
                                color="gray"
                                size="sm"
                            >
                                {{ __("import_templates.items.{$key}.download_blank_cross") }}
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ __('import_templates.not_importable.title') }}</h3>
            <ul class="mt-2 list-disc ps-5 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                @foreach (__('import_templates.not_importable.items') as $item)
                    <li>{{ $item }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</x-filament-panels::page>
