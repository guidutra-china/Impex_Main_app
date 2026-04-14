<x-filament-panels::page>
    {{-- Filters (all inputs use wire:model.live, no submit needed) --}}
    <div class="fi-section-content p-6 space-y-4 bg-white rounded-xl shadow-sm dark:bg-gray-900">
        <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
            <label class="md:col-span-2">
                <span class="block text-sm font-medium mb-1">{{ __('accounts_payable.filters.period') }}</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="preset">
                        <option value="7">{{ __('accounts_payable.filters.preset_7_days') }}</option>
                        <option value="30">{{ __('accounts_payable.filters.preset_30_days') }}</option>
                        <option value="90">{{ __('accounts_payable.filters.preset_90_days') }}</option>
                        <option value="this_month">{{ __('accounts_payable.filters.preset_this_month') }}</option>
                        <option value="next_month">{{ __('accounts_payable.filters.preset_next_month') }}</option>
                        <option value="custom">{{ __('accounts_payable.filters.preset_custom') }}</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>

            @if ($preset === 'custom')
                <label>
                    <span class="block text-sm font-medium mb-1">{{ __('accounts_payable.filters.date_from') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="customFrom" />
                    </x-filament::input.wrapper>
                </label>
                <label>
                    <span class="block text-sm font-medium mb-1">{{ __('accounts_payable.filters.date_to') }}</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="customTo" />
                    </x-filament::input.wrapper>
                </label>
            @endif

            <div class="md:col-span-2 flex items-center gap-6 mt-6">
                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="includeOverdue" />
                    <span>{{ __('accounts_payable.filters.include_overdue') }}</span>
                </label>

                <label class="flex items-center gap-2">
                    <input type="checkbox" wire:model.live="includePaid" />
                    <span>{{ __('accounts_payable.filters.include_paid') }}</span>
                </label>
            </div>
        </div>
    </div>

    {{-- KPI cards --}}
    @php $report = $this->getReport(); @endphp
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <x-filament::section>
            <div class="text-xs uppercase text-gray-500">{{ __('accounts_payable.kpis.overdue') }}</div>
            @foreach ($report->overdueTotalsByCurrency as $currency => $amount)
                <div class="text-lg font-semibold text-danger-600">{{ $currency }} {{ number_format($amount / 100, 2) }}</div>
            @endforeach
            @if (empty($report->overdueTotalsByCurrency))
                <div class="text-lg font-semibold">—</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs uppercase text-gray-500">{{ __('accounts_payable.kpis.period') }}</div>
            @foreach ($report->periodTotalsByCurrency as $currency => $amount)
                <div class="text-lg font-semibold">{{ $currency }} {{ number_format($amount / 100, 2) }}</div>
            @endforeach
            @if (empty($report->periodTotalsByCurrency))
                <div class="text-lg font-semibold">—</div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <div class="text-xs uppercase text-gray-500">{{ __('accounts_payable.kpis.total') }}</div>
            @foreach ($report->grandTotalsByCurrency as $currency => $amount)
                <div class="text-lg font-semibold">{{ $currency }} {{ number_format($amount / 100, 2) }}</div>
            @endforeach
            @if (empty($report->grandTotalsByCurrency))
                <div class="text-lg font-semibold">—</div>
            @endif
        </x-filament::section>
    </div>

    {{-- Overdue section --}}
    @if ($report->overdueItems->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">
                <span class="text-danger-600">🔴 {{ __('accounts_payable.groups.overdue') }}</span>
                <span class="text-sm text-gray-500">
                    ({{ trans_choice('accounts_payable.groups.items_count', $report->overdueItems->count(), ['count' => $report->overdueItems->count()]) }})
                </span>
            </x-slot>
            @include('filament.portal.pages.partials.accounts-payable-table', ['items' => $report->overdueItems])
        </x-filament::section>
    @endif

    {{-- Period groups --}}
    @forelse ($report->periodGroups as $group)
        <x-filament::section>
            <x-slot name="heading">
                📅 {{ $group->label }}
                <span class="text-sm text-gray-500">
                    ({{ trans_choice('accounts_payable.groups.items_count', $group->count(), ['count' => $group->count()]) }})
                </span>
            </x-slot>
            @include('filament.portal.pages.partials.accounts-payable-table', ['items' => $group->items])
        </x-filament::section>
    @empty
        @if ($report->overdueItems->isEmpty())
            <x-filament::section>
                <p class="text-center text-gray-500 py-8">{{ __('accounts_payable.empty_state') }}</p>
            </x-filament::section>
        @endif
    @endforelse
</x-filament-panels::page>
