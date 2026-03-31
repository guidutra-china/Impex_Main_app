<div wire:key="ps-components-{{ $schedule->id }}"
     class="border border-gray-200 dark:border-white/10 rounded-lg">

    <button wire:click="$toggle('isExpanded')"
            class="w-full flex items-center justify-between px-4 py-3 bg-gray-50 dark:bg-white/5 rounded-lg text-left hover:bg-gray-100 dark:hover:bg-white/10 transition-colors">
        <div class="flex items-center gap-2 font-semibold text-sm text-gray-700 dark:text-gray-200">
            <x-heroicon-o-wrench-screwdriver class="w-4 h-4"/>
            Component / Parts Inventory
        </div>
        <x-heroicon-o-chevron-down class="w-4 h-4 text-gray-400 transition-transform {{ $isExpanded ? 'rotate-180' : '' }}"/>
    </button>

    @if($isExpanded)
        <div class="divide-y divide-gray-100 dark:divide-white/10">
            @foreach($items as $item)
                @php
                    $isExpanded_item = in_array($item['id'], $expandedItems);
                    $pl = $item['productLevel'];
                @endphp
                <div class="px-4 py-3">
                    <div class="flex items-center gap-3">
                        <span class="font-medium text-sm text-gray-800 dark:text-gray-200 flex-1">
                            {{ $item['name'] }}
                        </span>

                        @if($pl)
                            <x-filament::badge :color="$pl->status->getColor()">
                                {{ $pl->status->getLabel() }}
                                @if($pl->eta) &middot; ETA {{ $pl->eta->format('d/m/Y') }} @endif
                            </x-filament::badge>
                        @endif

                        @if($this->canEdit())
                            <select
                                wire:change="saveComponent({{ $item['id'] }}, null, $event.target.value, null, null)"
                                class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5 text-gray-700 dark:text-gray-300">
                                <option value="">Set status...</option>
                                @foreach($componentStatuses as $status)
                                    <option value="{{ $status->value }}"
                                        {{ $pl?->status === $status ? 'selected' : '' }}>
                                        {{ $status->getLabel() }}
                                    </option>
                                @endforeach
                            </select>

                            <button wire:click="toggleExpand({{ $item['id'] }})"
                                    class="text-xs text-primary-600 hover:underline">
                                {{ $isExpanded_item ? 'Hide' : 'Sub-components' }}
                            </button>
                        @elseif(count($item['subComponents']) > 0)
                            <button wire:click="toggleExpand({{ $item['id'] }})"
                                    class="text-xs text-primary-600 hover:underline">
                                {{ count($item['subComponents']) }} component(s)
                            </button>
                        @endif
                    </div>

                    @if($isExpanded_item)
                        <div class="mt-2 ml-4 space-y-1.5">
                            @foreach($item['subComponents'] as $comp)
                                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                    <span class="flex-1 font-medium">{{ $comp->component_name }}</span>
                                    <x-filament::badge size="sm" :color="$comp->status->getColor()">
                                        {{ $comp->status->getLabel() }}
                                    </x-filament::badge>
                                    @if($comp->supplier_name)
                                        <span class="text-gray-400">{{ $comp->supplier_name }}</span>
                                    @endif
                                    @if($comp->eta)
                                        <span class="{{ $comp->eta->isPast() ? 'text-red-500' : 'text-gray-400' }}">
                                            ETA {{ $comp->eta->format('d/m/Y') }}
                                        </span>
                                    @endif
                                    @if($this->canEdit())
                                        <button wire:click="deleteComponent({{ $comp->id }})"
                                                class="text-red-400 hover:text-red-600">&#10005;</button>
                                    @endif
                                </div>
                            @endforeach

                            @if($this->canEdit())
                                <div x-data="{ name:'', status:'at_supplier', supplier:'', eta:'' }"
                                     class="flex items-center gap-1.5 pt-1 border-t border-dashed border-gray-200 dark:border-white/10">
                                    <input x-model="name" type="text" placeholder="Component name"
                                           class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5 w-28">
                                    <select x-model="status"
                                            class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5">
                                        @foreach($componentStatuses as $st)
                                            <option value="{{ $st->value }}">{{ $st->getLabel() }}</option>
                                        @endforeach
                                    </select>
                                    <input x-model="supplier" type="text" placeholder="Supplier"
                                           class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5 w-24">
                                    <input x-model="eta" type="date"
                                           class="text-xs border border-gray-300 dark:border-white/20 rounded px-2 py-1 bg-white dark:bg-white/5">
                                    <button
                                        @click="$wire.saveComponent({{ $item['id'] }}, name, status, supplier, eta); name=''; supplier=''; eta='';"
                                        class="text-xs bg-primary-600 text-white px-2 py-1 rounded hover:bg-primary-700">
                                        Add
                                    </button>
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
