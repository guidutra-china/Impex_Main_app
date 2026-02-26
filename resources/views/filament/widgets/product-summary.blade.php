<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-col gap-6 lg:flex-row">
            {{-- Left: Image + Identity --}}
            <div class="flex flex-col items-center gap-4 lg:w-56 lg:shrink-0">
                @if ($product->avatar)
                    <img
                        src="{{ Storage::url($product->avatar) }}"
                        alt="{{ $product->name }}"
                        class="h-40 w-40 rounded-xl border border-gray-200 object-cover shadow-sm dark:border-white/10"
                    />
                @else
                    <div class="flex h-40 w-40 items-center justify-center rounded-xl border border-gray-200 bg-gray-100 dark:border-white/10 dark:bg-white/5">
                        <x-filament::icon icon="heroicon-o-cube" class="h-16 w-16 text-gray-300 dark:text-gray-600" />
                    </div>
                @endif

                <div class="flex flex-wrap justify-center gap-1.5">
                    <x-filament::badge :color="$product->status->getColor()">
                        {{ $product->status->getLabel() }}
                    </x-filament::badge>
                    @if ($product->is_variant)
                        <x-filament::badge color="info" icon="heroicon-o-link">
                            {{ __('widgets.product_summary.variant') }}
                        </x-filament::badge>
                    @endif
                </div>
            </div>

            {{-- Right: Details --}}
            <div class="flex min-w-0 flex-1 flex-col gap-5">
                {{-- Header: Name + SKU + Category --}}
                <div>
                    <h2 class="text-xl font-bold text-gray-950 dark:text-white">{{ $product->name }}</h2>
                    <div class="mt-1 flex flex-wrap items-center gap-3 text-sm text-gray-500 dark:text-gray-400">
                        <span class="inline-flex items-center gap-1 font-mono text-xs">
                            <x-filament::icon icon="heroicon-o-qr-code" class="h-3.5 w-3.5" />
                            {{ $product->sku }}
                        </span>
                        @if ($product->category)
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-folder" class="h-3.5 w-3.5" />
                                {{ $product->category->full_path }}
                            </span>
                        @endif
                        @if ($product->parent)
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-link" class="h-3.5 w-3.5" />
                                Variant of {{ $product->parent->name }}
                            </span>
                        @endif
                        @if ($product->hs_code)
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-globe-alt" class="h-3.5 w-3.5" />
                                HS {{ $product->hs_code }}
                            </span>
                        @endif
                        @if ($product->origin_country)
                            <span class="inline-flex items-center gap-1">
                                <x-filament::icon icon="heroicon-o-flag" class="h-3.5 w-3.5" />
                                {{ $product->origin_country }}
                            </span>
                        @endif
                    </div>
                    @if ($product->tags->isNotEmpty())
                        <div class="mt-2 flex flex-wrap gap-1">
                            @foreach ($product->tags as $tag)
                                <x-filament::badge color="gray" size="sm">{{ $tag->name }}</x-filament::badge>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Stats Cards --}}
                <div class="grid grid-cols-3 gap-3">
                    @foreach ($stats as $stat)
                        <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                            <div class="flex items-center gap-3">
                                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-{{ $stat['color'] }}-100 dark:bg-{{ $stat['color'] }}-500/10">
                                    <x-filament::icon :icon="$stat['icon']" class="h-4.5 w-4.5 text-{{ $stat['color'] }}-600 dark:text-{{ $stat['color'] }}-400" />
                                </div>
                                <div>
                                    <p class="text-2xl font-bold text-gray-950 dark:text-white">{{ $stat['value'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $stat['label'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Pricing Row --}}
                @if (count($pricing) > 0)
                    <div>
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Pricing</h3>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-{{ min(count($pricing), 5) }}">
                            @foreach ($pricing as $key => $price)
                                <div class="rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-white/5">
                                    <p class="text-[0.65rem] font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $price['label'] }}</p>
                                    <p class="mt-0.5 text-base font-bold {{ $key === 'selling_price' ? 'text-success-600 dark:text-success-400' : ($key === 'supplier_price' ? 'text-primary-600 dark:text-primary-400' : 'text-gray-950 dark:text-white') }}">
                                        @if ($price['currency'])
                                            <span class="text-xs font-normal text-gray-400">{{ $price['currency'] }}</span>
                                        @endif
                                        {{ $price['value'] }}
                                    </p>
                                    @if (isset($price['note']))
                                        <p class="mt-0.5 truncate text-[0.6rem] text-gray-400 dark:text-gray-500">{{ $price['note'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Quick Specs + Attributes --}}
                @php
                    $allSpecs = array_merge($quickSpecs, $attributes);
                @endphp
                @if (count($allSpecs) > 0)
                    <div>
                        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Specifications & Attributes</h3>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($allSpecs as $spec)
                                <div class="flex items-baseline gap-1 text-sm">
                                    <span class="text-gray-500 dark:text-gray-400">{{ $spec['label'] }}:</span>
                                    <span class="font-medium text-gray-950 dark:text-white">
                                        {{ $spec['value'] }}{{ isset($spec['unit']) && $spec['unit'] ? ' ' . $spec['unit'] : '' }}
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
