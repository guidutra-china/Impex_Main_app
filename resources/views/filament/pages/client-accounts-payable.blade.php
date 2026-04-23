<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Filters --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <form wire:submit.prevent="refresh">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('client_accounts_payable.filters.from') }}</label>
                        <input type="date" wire:model="fromDate"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white" />
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('client_accounts_payable.filters.to') }}</label>
                        <input type="date" wire:model="toDate"
                            class="fi-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white" />
                    </div>
                    <div>
                        <label class="fi-fo-field-wrp-label text-sm font-medium text-gray-950 dark:text-white">{{ __('client_accounts_payable.filters.language') }}</label>
                        <select wire:model="locale"
                            class="fi-select-input block w-full rounded-lg border-gray-300 shadow-sm mt-1 text-sm dark:bg-white/5 dark:border-white/10 dark:text-white">
                            <option value="en">English</option>
                            <option value="pt_BR">Português (Brasil)</option>
                            <option value="zh_CN">中文 (简体)</option>
                        </select>
                    </div>
                    <div class="flex items-end gap-2">
                        <x-filament::button type="submit" size="sm">
                            {{ __('client_accounts_payable.filters.refresh') }}
                        </x-filament::button>
                    </div>
                </div>

                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model.live="openOnly"
                        class="fi-checkbox-input rounded border-gray-300 text-primary-600 shadow-sm dark:border-white/10 dark:bg-white/5" />
                    {{ __('client_accounts_payable.filters.open_only') }}
                </label>
            </form>
        </div>

        {{-- Preview --}}
        @php($report = $this->report)
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6 overflow-x-auto">
            <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                <div>
                    <h2 style="font-size: 18px; font-weight: 700; margin: 0;">
                        {{ __('client_accounts_payable.title') }}
                    </h2>
                    <div style="font-weight: 600;">{{ $report->company->name }}</div>
                </div>
                <div style="text-align: right; font-size: 12px; color: #555;">
                    <div>{{ __('client_accounts_payable.generated_at') }}: {{ $report->generatedAt->format('Y-m-d') }}</div>
                    @if($report->periodFrom || $report->periodTo)
                        <div>
                            {{ __('client_accounts_payable.period') }}:
                            {{ $report->periodFrom?->format('Y-m-d') ?? '—' }} → {{ $report->periodTo?->format('Y-m-d') ?? '—' }}
                        </div>
                    @endif
                    <div>
                        {{ __('client_accounts_payable.scope') }}:
                        {{ $report->openOnly ? __('client_accounts_payable.scope_open') : __('client_accounts_payable.scope_all') }}
                    </div>
                </div>
            </div>

            @if(empty($report->rows))
                <p style="color: #777; margin-top: 16px;">{{ __('client_accounts_payable.no_records') }}</p>
            @else
                <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                    <thead>
                        <tr style="background: #f3f4f6;">
                            <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.document') }}</th>
                            <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.installment') }}</th>
                            <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.due_date') }}</th>
                            <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.amount') }}</th>
                            <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.paid') }}</th>
                            <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.balance') }}</th>
                            <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.currency') }}</th>
                            <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($report->rows as $row)
                        @php($isOverdue = ! empty($row['days_overdue']))
                        <tr style="{{ $isOverdue ? 'background: #fef2f2;' : '' }}">
                            <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ $row['document'] }}</td>
                            <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">
                                {{ $row['installment'] }}
                                @if($isOverdue)
                                    <span style="color: #dc2626; font-size: 10px;">
                                        ({{ $row['days_overdue'] }} {{ __('client_accounts_payable.days_overdue') }})
                                    </span>
                                @endif
                            </td>
                            <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ $row['due_date'] ?? '—' }}</td>
                            <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format((float) $row['amount'], 2) }}</td>
                            <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ number_format((float) $row['paid'], 2) }}</td>
                            <td style="text-align: right; padding: 5px 8px; border-bottom: 1px solid #f3f4f6; font-weight: 600;">{{ number_format((float) $row['balance'], 2) }}</td>
                            <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ $row['currency'] }}</td>
                            <td style="padding: 5px 8px; border-bottom: 1px solid #f3f4f6;">{{ $row['status'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>

                @if(! empty($report->totals))
                    <div style="margin-top: 12px;">
                        <div style="font-weight: 600; font-size: 12px; margin-bottom: 4px;">
                            {{ __('client_accounts_payable.totals_by_currency') }}
                        </div>
                        <table style="width: 100%; border-collapse: collapse; font-size: 12px;">
                            <thead>
                                <tr style="background: #f3f4f6;">
                                    <th style="text-align: left; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.currency') }}</th>
                                    <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.amount') }}</th>
                                    <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.paid') }}</th>
                                    <th style="text-align: right; padding: 6px 8px; border-bottom: 2px solid #e5e7eb;">{{ __('client_accounts_payable.columns.balance') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @foreach($report->totals as $t)
                                <tr>
                                    <td style="padding: 5px 8px;">{{ $t['currency'] }}</td>
                                    <td style="text-align: right; padding: 5px 8px;">{{ number_format((float) $t['total'], 2) }}</td>
                                    <td style="text-align: right; padding: 5px 8px;">{{ number_format((float) $t['paid'], 2) }}</td>
                                    <td style="text-align: right; padding: 5px 8px; font-weight: 700;">{{ number_format((float) $t['balance'], 2) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

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
    </div>
</x-filament-panels::page>
