<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('widgets.my_projects.heading')"
        icon="heroicon-o-briefcase"
        :description="$totalActive . ' ' . __('widgets.my_projects.active_items')"
        collapsible
    >
        {{-- Summary Counters --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            @php
                $stages = [
                    ['key' => 'inquiries', 'label' => __('widgets.my_projects.inquiries'), 'icon' => 'heroicon-o-magnifying-glass', 'color' => 'info', 'url' => route('filament.admin.resources.inquiries.index')],
                    ['key' => 'quotations', 'label' => __('widgets.my_projects.quotations'), 'icon' => 'heroicon-o-document-currency-dollar', 'color' => 'primary', 'url' => route('filament.admin.resources.quotations.index')],
                    ['key' => 'supplier_quotations', 'label' => __('widgets.my_projects.supplier_quotations'), 'icon' => 'heroicon-o-clipboard-document-list', 'color' => 'gray', 'url' => route('filament.admin.resources.supplier-quotations.index')],
                    ['key' => 'proforma_invoices', 'label' => __('widgets.my_projects.proforma_invoices'), 'icon' => 'heroicon-o-document-text', 'color' => 'warning', 'url' => route('filament.admin.resources.proforma-invoices.index')],
                    ['key' => 'purchase_orders', 'label' => __('widgets.my_projects.purchase_orders'), 'icon' => 'heroicon-o-shopping-cart', 'color' => 'success', 'url' => route('filament.admin.resources.purchase-orders.index')],
                    ['key' => 'shipments', 'label' => __('widgets.my_projects.shipments'), 'icon' => 'heroicon-o-truck', 'color' => 'danger', 'url' => route('filament.admin.resources.shipments.index')],
                ];
            @endphp

            @foreach ($stages as $stage)
                <a href="{{ $stage['url'] }}?tableFilters[my_projects][isActive]=1"
                   @class([
                       'group flex flex-col items-center rounded-lg border p-3 text-center transition-all hover:shadow-md',
                       'border-info-200 bg-info-50/50 hover:bg-info-50 dark:border-info-500/20 dark:bg-info-500/5 dark:hover:bg-info-500/10' => $stage['color'] === 'info',
                       'border-primary-200 bg-primary-50/50 hover:bg-primary-50 dark:border-primary-500/20 dark:bg-primary-500/5 dark:hover:bg-primary-500/10' => $stage['color'] === 'primary',
                       'border-gray-200 bg-gray-50/50 hover:bg-gray-50 dark:border-gray-500/20 dark:bg-gray-500/5 dark:hover:bg-gray-500/10' => $stage['color'] === 'gray',
                       'border-warning-200 bg-warning-50/50 hover:bg-warning-50 dark:border-warning-500/20 dark:bg-warning-500/5 dark:hover:bg-warning-500/10' => $stage['color'] === 'warning',
                       'border-success-200 bg-success-50/50 hover:bg-success-50 dark:border-success-500/20 dark:bg-success-500/5 dark:hover:bg-success-500/10' => $stage['color'] === 'success',
                       'border-danger-200 bg-danger-50/50 hover:bg-danger-50 dark:border-danger-500/20 dark:bg-danger-500/5 dark:hover:bg-danger-500/10' => $stage['color'] === 'danger',
                   ])
                >
                    <x-filament::icon
                        :icon="$stage['icon']"
                        @class([
                            'mb-1 h-5 w-5',
                            'text-info-400 dark:text-info-500' => $stage['color'] === 'info',
                            'text-primary-400 dark:text-primary-500' => $stage['color'] === 'primary',
                            'text-gray-400 dark:text-gray-500' => $stage['color'] === 'gray',
                            'text-warning-400 dark:text-warning-500' => $stage['color'] === 'warning',
                            'text-success-400 dark:text-success-500' => $stage['color'] === 'success',
                            'text-danger-400 dark:text-danger-500' => $stage['color'] === 'danger',
                        ])
                    />
                    <span @class([
                        'text-2xl font-bold',
                        'text-info-700 dark:text-info-300' => $stage['color'] === 'info',
                        'text-primary-700 dark:text-primary-300' => $stage['color'] === 'primary',
                        'text-gray-700 dark:text-gray-300' => $stage['color'] === 'gray',
                        'text-warning-700 dark:text-warning-300' => $stage['color'] === 'warning',
                        'text-success-700 dark:text-success-300' => $stage['color'] === 'success',
                        'text-danger-700 dark:text-danger-300' => $stage['color'] === 'danger',
                    ])>
                        {{ $counts[$stage['key']] }}
                    </span>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ $stage['label'] }}
                    </span>
                </a>
            @endforeach
        </div>

        {{-- Urgent Attention Items --}}
        @if (count($urgentItems) > 0)
            <div class="mt-4 space-y-2">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('widgets.my_projects.needs_attention') }}
                </h4>
                @foreach ($urgentItems as $item)
                    <a href="{{ $item['url'] }}?tableFilters[my_projects][isActive]=1"
                       @class([
                           'flex items-center gap-3 rounded-lg border p-2.5 transition-all hover:shadow-sm',
                           'border-warning-200 bg-warning-50/50 dark:border-warning-500/20 dark:bg-warning-500/5' => $item['type'] === 'warning',
                           'border-info-200 bg-info-50/50 dark:border-info-500/20 dark:bg-info-500/5' => $item['type'] === 'info',
                           'border-danger-200 bg-danger-50/50 dark:border-danger-500/20 dark:bg-danger-500/5' => $item['type'] === 'danger',
                       ])
                    >
                        <x-filament::icon
                            :icon="$item['icon']"
                            @class([
                                'h-5 w-5 shrink-0',
                                'text-warning-500' => $item['type'] === 'warning',
                                'text-info-500' => $item['type'] === 'info',
                                'text-danger-500' => $item['type'] === 'danger',
                            ])
                        />
                        <span @class([
                            'text-sm font-medium',
                            'text-warning-700 dark:text-warning-400' => $item['type'] === 'warning',
                            'text-info-700 dark:text-info-400' => $item['type'] === 'info',
                            'text-danger-700 dark:text-danger-400' => $item['type'] === 'danger',
                        ])>
                            {{ $item['text'] }}
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Recent Inquiries Table --}}
        @if ($myInquiries->isNotEmpty())
            <div class="mt-4">
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                    {{ __('widgets.my_projects.recent_inquiries') }}
                </h4>
                <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-800">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.my_projects.reference') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.my_projects.client') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.my_projects.status') }}</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('widgets.my_projects.updated') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach ($myInquiries as $inquiry)
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                    <td class="px-3 py-2">
                                        <a href="{{ route('filament.admin.resources.inquiries.edit', $inquiry) }}"
                                           class="font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                            {{ $inquiry->reference_number ?? '#' . $inquiry->id }}
                                        </a>
                                    </td>
                                    <td class="px-3 py-2 text-gray-600 dark:text-gray-400">
                                        {{ $inquiry->client?->name ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => in_array($inquiry->status->value, ['draft']),
                                            'bg-info-100 text-info-700 dark:bg-info-500/10 dark:text-info-400' => in_array($inquiry->status->value, ['received', 'quoting']),
                                            'bg-primary-100 text-primary-700 dark:bg-primary-500/10 dark:text-primary-400' => in_array($inquiry->status->value, ['quoted']),
                                            'bg-warning-100 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400' => in_array($inquiry->status->value, ['negotiating']),
                                            'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' => in_array($inquiry->status->value, ['won']),
                                        ])>
                                            {{ $inquiry->status->getLabel() }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-500 dark:text-gray-400">
                                        {{ $inquiry->updated_at->diffForHumans() }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @else
            <div class="mt-4 flex items-center gap-3 rounded-lg bg-gray-50 p-4 dark:bg-gray-800/50">
                <x-filament::icon icon="heroicon-o-inbox" class="h-6 w-6 text-gray-400" />
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('widgets.my_projects.no_projects_assigned') }}</p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
