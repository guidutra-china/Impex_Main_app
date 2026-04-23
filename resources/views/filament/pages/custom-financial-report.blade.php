<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <form wire:submit.prevent="generate">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('financial_report.filters.from') }}</label>
                        <input type="date" wire:model="fromDate"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white" />
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('financial_report.filters.to') }}</label>
                        <input type="date" wire:model="toDate"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white" />
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('financial_report.filters.status_scope') }}</label>
                        <select wire:model="statusScope"
                            class="fi-select-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white">
                            <option value="all">{{ __('financial_report.filters.status_all') }}</option>
                            <option value="active">{{ __('financial_report.filters.status_active') }}</option>
                            <option value="closed">{{ __('financial_report.filters.status_closed') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('financial_report.filters.language') }}</label>
                        <select wire:model="locale"
                            class="fi-select-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white">
                            <option value="en">English</option>
                            <option value="pt_BR">Português (Brasil)</option>
                            <option value="zh_CN">中文 (简体)</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white mb-2 block">{{ __('financial_report.filters.sections') }}</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-x-4 gap-y-2">
                        @foreach($this->availableSections() as $section)
                            <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                <input type="checkbox" wire:model.live="sectionToggles.{{ $section }}"
                                    class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm dark:border-white/10 dark:bg-white/5" />
                                {{ __('financial_report.sections.' . $section) }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="flex items-center gap-4 flex-wrap">
                    <x-filament::button type="submit" size="sm">
                        {{ __('financial_report.filters.generate') }}
                    </x-filament::button>

                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 ml-4">
                        <input type="checkbox" wire:model.live="showDetails"
                            class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm dark:border-white/10 dark:bg-white/5" />
                        {{ __('custom_financial_report.show_details') }}
                        <span class="text-xs text-gray-500">{{ __('custom_financial_report.show_details_help') }}</span>
                    </label>
                </div>
            </form>
        </div>

        {{-- Exclusion summary --}}
        @php($totalExcluded = collect($excluded)->flatten()->count())
        @if($totalExcluded > 0)
            <div class="fi-section rounded-xl bg-amber-50 ring-1 ring-amber-200 dark:bg-amber-900/10 dark:ring-amber-800 p-4 text-sm text-amber-900 dark:text-amber-200">
                {{ __('custom_financial_report.exclusion_summary', ['count' => $totalExcluded]) }}
                <button type="button" wire:click="clearExclusions" class="ml-2 underline font-medium">
                    {{ __('custom_financial_report.actions.clear_exclusions') }}
                </button>
            </div>
        @endif

        {{-- Preview with inline checkboxes per entity row --}}
        @if($this->report)
            <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 overflow-x-auto">
                {{-- Report header --}}
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
                    <div>
                        <h2 style="font-size: 20px; font-weight: 700; margin: 0 0 4px 0;">
                            {{ __('financial_report.title') }}
                        </h2>
                        <div style="font-weight: 600;">{{ $this->report->company->name }}</div>
                        @if($this->report->company->email)
                            <div style="color: #777; font-size: 12px;">{{ $this->report->company->email }}</div>
                        @endif
                    </div>
                    <div style="text-align: right; font-size: 12px; color: #555;">
                        <div>{{ __('financial_report.period') }}: {{ $this->report->periodFrom->format('Y-m-d') }} → {{ $this->report->periodTo->format('Y-m-d') }}</div>
                        <div>{{ __('financial_report.generated_at') }}: {{ $this->report->generatedAt->format('Y-m-d') }}</div>
                    </div>
                </div>

                {{-- Financial summary --}}
                @if($this->report->financialSummary)
                    <h3 style="font-size: 14px; font-weight: 600; margin: 16px 0 6px 0; border-bottom: 1px solid #d1d5db; padding-bottom: 4px;">
                        {{ __('financial_report.financial_summary') }}
                    </h3>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 12px;">
                        <thead>
                            <tr style="background: #f3f4f6;">
                                <th style="text-align: left; padding: 6px 8px;">{{ __('financial_report.columns.currency') }}</th>
                                <th style="text-align: right; padding: 6px 8px;">{{ __('financial_report.invoiced') }}</th>
                                <th style="text-align: right; padding: 6px 8px;">{{ __('financial_report.paid') }}</th>
                                <th style="text-align: right; padding: 6px 8px;">{{ __('financial_report.open') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($this->report->financialSummary->totalsByCurrency as $t)
                            <tr>
                                <td style="padding: 5px 8px;">{{ $t->currency }}</td>
                                <td style="text-align: right; padding: 5px 8px;">{{ number_format($t->invoiced, 2) }}</td>
                                <td style="text-align: right; padding: 5px 8px;">{{ number_format($t->paid, 2) }}</td>
                                <td style="text-align: right; padding: 5px 8px; font-weight: 600;">{{ number_format($t->open, 2) }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                {{-- Sections --}}
                @php($sections = $this->report->nonEmptySections())
                @php($moneyColumns = ['total','paid','balance','amount','goods','freight','other_costs','total_costs','grand_total','additional_costs','allocated','unallocated','revenue','cost','commission','total_revenue','margin_amount'])

                @forelse($sections as $section)
                    @php($selectableCount = collect($section->rows)->filter(fn($r) => isset($r['_entity_id']))->count())
                    <h3 style="font-size: 14px; font-weight: 600; margin: 20px 0 6px 0; border-bottom: 1px solid #d1d5db; padding-bottom: 4px;">
                        {{ __($section->titleKey) }}
                        <span style="font-size: 11px; font-weight: 400; color: #777;">({{ $selectableCount }})</span>
                    </h3>
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 12px;">
                        <thead>
                            <tr style="background: #f3f4f6;">
                                <th style="width: 32px; padding: 6px 4px; border-bottom: 2px solid #e5e7eb;"></th>
                                @foreach($section->columns as $col)
                                    <th style="{{ in_array($col, $moneyColumns) ? 'text-align: right;' : 'text-align: left;' }} padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">
                                        {{ __('financial_report.columns.' . $col) }}
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($section->rows as $row)
                                @php($rowType = $row['_row_type'] ?? 'header')
                                @php($isDetail = $rowType === 'detail')
                                @php($entityId = $row['_entity_id'] ?? null)
                                @php($isExcluded = $entityId !== null && in_array((int) $entityId, $excluded[$section->key] ?? [], true))
                                <tr style="{{ $isDetail ? 'background: #f9fafb; font-size: 11px; color: #555;' : '' }}{{ $isExcluded ? ' opacity: 0.35; text-decoration: line-through;' : '' }}">
                                    <td style="padding: 4px; border-bottom: 1px solid {{ $isDetail ? '#eee' : '#f3f4f6' }}; text-align: center;">
                                        @if($entityId !== null && ! $isDetail)
                                            <input type="checkbox"
                                                wire:click="toggleRow('{{ $section->key }}', {{ $entityId }})"
                                                @checked(! $isExcluded)
                                                class="rounded border-gray-300 text-primary-600 shadow-sm" />
                                        @endif
                                    </td>
                                    @foreach($section->columns as $col)
                                        @php($val = $row[$col] ?? null)
                                        @php($isMoney = in_array($col, $moneyColumns))
                                        <td style="{{ $isMoney ? 'text-align: right;' : '' }} padding: {{ $isDetail ? '3px 8px' : '5px 8px' }}; border-bottom: 1px solid {{ $isDetail ? '#eee' : '#f3f4f6' }};{{ $isDetail && $loop->first ? ' padding-left: 20px;' : '' }}">
                                            @if($val === null || $val === '')
                                                {{ $isDetail ? '' : '—' }}
                                            @elseif($isMoney)
                                                {{ number_format((float) $val, 2) }}
                                            @else
                                                {{ $val }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @empty
                    @if(! $this->report->financialSummary)
                        <p style="color: #777;">{{ __('financial_report.no_records') }}</p>
                    @endif
                @endforelse

                {{-- Previously sent versions --}}
                @php($history = $this->getPreviouslySentDocuments())
                @if($history->isNotEmpty())
                    <div class="mt-8 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold text-sm mb-2 text-gray-800 dark:text-gray-100">
                            {{ __('custom_financial_report.previous_versions') }}
                        </h3>
                        <ul class="text-xs text-gray-600 dark:text-gray-400 space-y-1">
                            @foreach($history as $doc)
                                <li>
                                    v{{ $doc->version }} — {{ $doc->name }}
                                    <span class="text-gray-400">({{ $doc->created_at->format('Y-m-d H:i') }})</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
