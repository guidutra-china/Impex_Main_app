{{--
    Parcelas iguais (mesmo estágio e moeda — InstallmentStage) viram um grupo
    RECOLHIDO: o cabeçalho usa as mesmas colunas da tabela para mostrar a soma
    de valor, pago e restante; expandir revela de quais PIs/embarques são.
    Parcela única continua sendo uma linha comum — um grupo de um só custaria
    um clique para mostrar o mesmo número.
--}}
@php
    $stageGroups = \App\Domain\Financial\Support\InstallmentStageGrouper::group($items);
@endphp
<table class="w-full text-sm">
    <thead class="bg-gray-50 dark:bg-gray-800">
        <tr class="text-left">
            <th class="p-2">{{ __('accounts_payable.columns.due_date') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.reference') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.description') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.currency') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.amount') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.paid') }}</th>
            <th class="p-2 text-right">{{ __('accounts_payable.columns.remaining') }}</th>
            <th class="p-2">{{ __('accounts_payable.columns.status') }}</th>
        </tr>
    </thead>
    @foreach ($stageGroups as $stageGroup)
        @if ($stageGroup['count'] === 1)
            <tbody>
                @include('filament.portal.pages.partials.accounts-payable-row', ['item' => $stageGroup['items']->first(), 'nested' => false])
            </tbody>
        @else
            @php
                $groupTitle = $stageGroup['title'] ?? match ($stageGroup['bucket']) {
                    'credit' => __('accounts_payable.groups.credits'),
                    default => __('accounts_payable.groups.additional_costs'),
                };
            @endphp
            <tbody x-data="{ open: false }">
                <tr
                    class="cursor-pointer border-t border-gray-200 bg-gray-50 font-medium hover:bg-gray-100 dark:border-gray-700 dark:bg-gray-800/60 dark:hover:bg-gray-800"
                    x-on:click="open = ! open"
                >
                    <td class="p-2">
                        <button
                            type="button"
                            class="inline-flex items-center gap-1"
                            x-bind:aria-expanded="open.toString()"
                            aria-label="{{ __('accounts_payable.groups.toggle_group') }}"
                            x-on:click.stop="open = ! open"
                        >
                            <span class="inline-block transition-transform" x-bind:class="open ? 'rotate-90' : ''">▸</span>
                            <span>{{ $stageGroup['earliest_due']?->format('d/m/Y') ?? '—' }}</span>
                        </button>
                    </td>
                    <td class="p-2 text-xs text-gray-500">
                        {{ trans_choice('accounts_payable.groups.installments_count', $stageGroup['count'], ['count' => $stageGroup['count']]) }}
                    </td>
                    <td class="p-2">{{ $groupTitle }}</td>
                    <td class="p-2">{{ $stageGroup['currency'] }}</td>
                    <td class="p-2 text-right">{{ number_format($stageGroup['amount'] / 10000, 2) }}</td>
                    <td class="p-2 text-right">{{ number_format($stageGroup['paid'] / 10000, 2) }}</td>
                    <td class="p-2 text-right font-semibold">{{ number_format($stageGroup['remaining'] / 10000, 2) }}</td>
                    <td class="p-2"></td>
                </tr>
                @foreach ($stageGroup['items'] as $item)
                    @include('filament.portal.pages.partials.accounts-payable-row', ['item' => $item, 'nested' => true])
                @endforeach
            </tbody>
        @endif
    @endforeach
</table>
