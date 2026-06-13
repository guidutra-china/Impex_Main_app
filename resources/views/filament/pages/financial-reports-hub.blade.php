<x-filament-panels::page>
    <div class="space-y-8">
        {{-- General reports --}}
        <div>
            <h2 class="text-base font-semibold text-gray-950 dark:text-white mb-4">
                {{ __('financial_reports_hub.sections.general') }}
            </h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->generalReports as $card)
                    <a
                        href="{{ $card['url'] }}"
                        class="block rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-white/5"
                    >
                        <div class="flex items-start gap-3">
                            <x-filament::icon
                                :icon="$card['icon']"
                                class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400"
                            />
                            <div>
                                <p class="font-medium text-gray-950 dark:text-white">
                                    {{ $card['title'] }}
                                </p>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $card['description'] }}
                                </p>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Company-scoped reports --}}
        <div>
            <h2 class="text-base font-semibold text-gray-950 dark:text-white mb-4">
                {{ __('financial_reports_hub.sections.by_company') }}
            </h2>

            <div class="mb-4 max-w-md">
                <label
                    for="hub-company"
                    class="mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300"
                >
                    {{ __('financial_reports_hub.fields.company') }}
                </label>
                <select
                    id="hub-company"
                    wire:model.live="companyId"
                    class="fi-select-input block w-full rounded-lg border-none bg-white shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm"
                >
                    <option value="">{{ __('financial_reports_hub.fields.company_placeholder') }}</option>
                    @foreach ($this->companyOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>

            @if ($this->companyId)
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($this->companyReports as $card)
                        <a
                            href="{{ $card['url'] }}"
                            class="block rounded-xl bg-white p-5 shadow-sm ring-1 ring-gray-950/5 transition hover:bg-gray-50 dark:bg-gray-900 dark:ring-white/10 dark:hover:bg-white/5"
                        >
                            <div class="flex items-start gap-3">
                                <x-filament::icon
                                    :icon="$card['icon']"
                                    class="h-6 w-6 shrink-0 text-primary-600 dark:text-primary-400"
                                />
                                <div>
                                    <p class="font-medium text-gray-950 dark:text-white">
                                        {{ $card['title'] }}
                                    </p>
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                        {{ $card['description'] }}
                                    </p>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('financial_reports_hub.fields.company_hint') }}
                </p>
            @endif
        </div>
    </div>
</x-filament-panels::page>
