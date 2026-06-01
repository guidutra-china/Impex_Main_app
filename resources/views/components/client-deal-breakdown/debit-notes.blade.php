@props(['rows', 'presentationCurrency'])

<div class="fi-section bg-white dark:bg-gray-900 rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10 p-4">
    <div class="flex items-center justify-between mb-3">
        <div class="text-sm uppercase font-bold text-purple-700 dark:text-purple-400">
            {{ __('client_deal_breakdown.sections.debit_notes') }} ({{ count($rows) }})
        </div>
    </div>

    @if (empty($rows))
        <div class="text-xs text-gray-500 italic">{{ __('client_deal_breakdown.no_debit_notes') }}</div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-gray-50 dark:bg-gray-800/50 text-gray-500 uppercase">
                    <tr>
                        <th class="p-2 text-left">DN</th>
                        <th class="p-2 text-left">{{ __('client_deal_breakdown.columns.issue_date') }}</th>
                        <th class="p-2 text-right">Total</th>
                        <th class="p-2 text-right">In {{ $presentationCurrency }}</th>
                        <th class="p-2 text-right">{{ __('forms.labels.paid') }}</th>
                        <th class="p-2 text-right">{{ __('forms.labels.remaining') }}</th>
                        <th class="p-2 text-left">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $dn)
                        <tr class="border-t border-gray-100 dark:border-gray-800">
                            <td class="p-2">
                                <a href="{{ $dn->detailUrl }}" target="_blank"
                                   class="text-primary-600 hover:underline"
                                   wire:click.stop>{{ $dn->reference }}</a>
                            </td>
                            <td class="p-2 text-gray-500">
                                {{ $dn->issuedAt?->format('Y-m-d') ?? '—' }}
                            </td>
                            <td class="p-2 text-right">
                                {{ \App\Domain\Infrastructure\Support\Money::format($dn->totalOriginal) }}
                                <span class="text-gray-500">{{ $dn->currencyOriginal }}</span>
                            </td>
                            <td class="p-2 text-right">
                                @if ($dn->totalPresentation !== null)
                                    {{ \App\Domain\Infrastructure\Support\Money::format($dn->totalPresentation) }}
                                @else
                                    <span class="text-amber-600">⚠</span>
                                @endif
                            </td>
                            <td class="p-2 text-right text-green-600">
                                {{ \App\Domain\Infrastructure\Support\Money::format($dn->paidOriginal) }}
                            </td>
                            <td class="p-2 text-right {{ $dn->outstandingOriginal > 0 ? 'text-amber-600' : 'text-gray-500' }}">
                                {{ \App\Domain\Infrastructure\Support\Money::format($dn->outstandingOriginal) }}
                            </td>
                            <td class="p-2">
                                <span class="px-2 py-0.5 rounded text-xs bg-gray-100 dark:bg-gray-800">
                                    {{ $dn->status->getLabel() }}
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
