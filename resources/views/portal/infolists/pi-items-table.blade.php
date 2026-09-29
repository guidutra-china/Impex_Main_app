@php
    $record = $getRecord();
    // Código e nome como o cliente conhece o produto (pivot do cliente >
    // model number > SKU), respeitando a preferência de nomenclatura — o
    // mesmo que sai no PDF da PI.
    $items = $record->items()->with('product.companies')->orderBy('sort_order')->get();
    $identity = \App\Domain\Catalog\Services\ProductIdentityResolver::forClientCompany($record->company);
    $showFinancial = auth()->user()?->can('portal:view-financial-summary');
    $currency = $record->currency_code ?? 'USD';
@endphp

@if($items->isNotEmpty())
    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
        <table class="w-full text-sm text-left">
            <thead class="bg-gray-50 dark:bg-white/5 text-gray-600 dark:text-gray-400 font-medium">
                <tr>
                    <th class="px-4 py-2.5 w-12"></th>
                    <th class="px-4 py-2.5">{{ __('widgets.portal.pi.code') }}</th>
                    <th class="px-4 py-2.5">{{ __('forms.labels.product') }}</th>
                    <th class="px-4 py-2.5">{{ __('forms.labels.description') }}</th>
                    <th class="px-4 py-2.5 text-center">{{ __('forms.labels.quantity') }}</th>
                    <th class="px-4 py-2.5 text-center">{{ __('forms.labels.unit') }}</th>
                    @if($showFinancial)
                        <th class="px-4 py-2.5 text-right">{{ __('forms.labels.unit_price') }}</th>
                        <th class="px-4 py-2.5 text-right">{{ __('forms.labels.line_total') }}</th>
                    @endif
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach($items as $item)
                    @php $productIdentity = $identity->resolve($item->product); @endphp
                    <tr class="text-gray-900 dark:text-white">
                        <td class="px-4 py-2.5">
                            @if($item->product?->avatar)
                                <img src="{{ Storage::disk('public')->url($item->product->avatar) }}" alt="{{ $item->product->name }}" class="w-10 h-10 rounded object-cover">
                            @else
                                <div class="w-10 h-10 rounded bg-gray-100 dark:bg-white/10 flex items-center justify-center">
                                    <x-heroicon-o-cube class="w-5 h-5 text-gray-400" />
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs whitespace-nowrap">{{ $productIdentity->codeOr('—') }}</td>
                        <td class="px-4 py-2.5">{{ $item->product?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ $item->description ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center">{{ number_format($item->quantity) }}</td>
                        <td class="px-4 py-2.5 text-center">{{ $item->unit ?? 'pcs' }}</td>
                        @if($showFinancial)
                            <td class="px-4 py-2.5 text-right">{{ \App\Domain\Infrastructure\Support\Money::format($item->unit_price) }}</td>
                            <td class="px-4 py-2.5 text-right font-bold">{{ \App\Domain\Infrastructure\Support\Money::format($item->line_total, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
            @if($showFinancial)
                <tfoot class="bg-gray-50 dark:bg-white/5 font-bold text-gray-900 dark:text-white">
                    <tr>
                        <td colspan="6" class="px-4 py-2.5 text-right">{{ __('forms.labels.subtotal') }}</td>
                        <td class="px-4 py-2.5"></td>
                        <td class="px-4 py-2.5 text-right">{{ $currency }} {{ \App\Domain\Infrastructure\Support\Money::format($record->total, 2) }}</td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>
@else
    <p class="text-sm text-gray-500 dark:text-gray-400 italic">{{ __('widgets.portal.pi.no_items') }}</p>
@endif
