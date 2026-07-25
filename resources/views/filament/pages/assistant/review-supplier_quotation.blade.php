@php($previewCurrency = strtoupper($form['cabecalho']['currency_code'] ?? 'USD'))
<div class="rounded-xl border border-primary-300 bg-primary-50 p-4 text-sm dark:border-primary-700 dark:bg-primary-950/40"
     x-data="{
         gallery: null,
         linkPanel: null,
         dropTarget: null,
         uploadPhotoFor(target, file) {
             if (! target && target !== 0) return;
             if (! file || ! file.type.startsWith('image/')) return;
             $wire.set('photoUploadTarget', target, false);
             $wire.upload('itemPhotoUpload', file);
         },
     }"
     x-on:paste.window="if (gallery !== null && $event.clipboardData?.files.length) { $event.preventDefault(); uploadPhotoFor(gallery, $event.clipboardData.files[0]); }">
    <p class="font-semibold">{{ __('assistant.preview_title') }}</p>
    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('assistant.review_hint') }}</p>

    @if (! empty($importDuplicateMatches))
        <div class="mt-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:border-amber-700 dark:bg-amber-950/40 dark:text-amber-300">
            {{ __('assistant.duplicate_file_banner', ['references' => collect($importDuplicateMatches)->pluck('reference')->implode(', ')]) }}
        </div>
    @endif

    @if ($importLockedInquiryId !== null)
        <p class="mt-1 text-xs font-medium text-primary-700 dark:text-primary-300">
            {{ __('assistant.sq_will_link_inquiry', ['inquiry' => $this->lockedInquiryLabel()]) }}
        </p>
    @endif

    {{-- Resumo --}}
    @php($novos = collect($form['itens'])->where('status', 'novo')->count())
    @php($semCat = collect($form['itens'])->filter(fn ($it) => blank($it['category_id'] ?? null))->count())
    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
        {{ __('assistant.summary_counts', ['total' => count($form['itens']), 'existing' => count($form['itens']) - $novos, 'new' => $novos]) }}
        @if ($semCat > 0)
            · {{ __('assistant.uncategorized_warning', ['count' => $semCat]) }}
        @endif
    </p>

    {{-- Fornecedor --}}
    <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
        <label class="col-span-2 text-xs">
            {{ __('assistant.supplier') }}
            <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] {{ ($form['fornecedor']['status'] ?? '') === 'novo' ? 'bg-amber-200 text-amber-900' : 'bg-green-200 text-green-900' }}">
                {{ ($form['fornecedor']['status'] ?? '') === 'novo' ? __('assistant.status_new') : __('assistant.status_existing') }}
            </span>
            <input type="text" wire:model="form.fornecedor.nome" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
        </label>
        <label class="text-xs">{{ __('assistant.currency') }}
            <input type="text" wire:model="form.cabecalho.currency_code" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
        </label>
        <label class="text-xs">Incoterm
            <input type="text" wire:model="form.cabecalho.incoterm" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
        </label>
    </div>

    {{-- Fluxo combinado: criar a Inquiry do cliente junto com a SQ (indisponível quando o import já está preso a uma inquiry) --}}
    @if ($importLockedInquiryId === null && auth()->user()->can('create-inquiries'))
        <div class="mt-2 rounded border border-gray-200 bg-white p-2 dark:border-white/10 dark:bg-gray-900">
            <label class="flex items-center gap-2 text-xs font-medium">
                <input type="checkbox" wire:model.live="form.criar_inquiry" class="rounded border-gray-300 dark:border-white/10" />
                {{ __('assistant.create_linked_inquiry') }}
            </label>
            @if ($form['criar_inquiry'] ?? false)
                <p class="mt-1 text-[11px] text-gray-500 dark:text-gray-400">{{ __('assistant.create_linked_inquiry_hint') }}</p>
                <div class="mt-1 grid grid-cols-1 gap-2 md:grid-cols-2">
                    <input type="search" wire:model.live.debounce.400ms="importClientSearch" placeholder="{{ __('assistant.search_client') }}"
                           class="block w-full rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" />
                    <select wire:model.live="form.inquiry_company_id" class="block w-full rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10">
                        <option value="">{{ __('assistant.select_client') }}</option>
                        @foreach ($this->importClientOptions() as $clientId => $clientName)
                            <option value="{{ $clientId }}">{{ $clientName }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    @endif

    {{-- Itens editáveis --}}
    <div class="mt-3 max-h-72 overflow-y-auto rounded border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-left text-xs">
            <thead class="sticky top-0 bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="w-14 px-2 py-1">{{ __('assistant.col.photo') }}</th>
                    <th class="w-24 px-2 py-1">{{ __('assistant.col.part_no') }}</th>
                    <th class="w-full px-2 py-1">{{ __('assistant.col.description') }}</th>
                    <th class="px-1 py-1 text-right">{{ __('assistant.col.qty') }}</th>
                    <th class="px-1 py-1">{{ __('assistant.col.unit') }}</th>
                    <th class="px-1 py-1 text-right">{{ __('assistant.col.unit_price') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.category') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($form['itens'] as $i => $item)
                    <tr class="border-t border-gray-100 align-top dark:border-white/5" wire:key="item-{{ $i }}">
                        <td class="px-2 py-1">
                            @php($thumb = $item['photo_index'] !== null ? $this->importImageThumb($item['photo_index']) : null)
                            <button type="button" x-on:click="gallery = (gallery === {{ $i }} ? null : {{ $i }})"
                                    x-on:dragover.prevent="dropTarget = {{ $i }}"
                                    x-on:dragleave="dropTarget = null"
                                    x-on:drop.prevent="dropTarget = null; uploadPhotoFor({{ $i }}, $event.dataTransfer.files[0])"
                                    :class="dropTarget === {{ $i }} ? 'ring-2 ring-primary-500' : ''"
                                    title="{{ __('assistant.photo_drop_hint') }}"
                                    class="flex h-12 w-12 items-center justify-center overflow-hidden rounded border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                @if ($thumb)
                                    <img src="{{ $thumb }}" class="h-full w-full object-cover" alt="" />
                                @else
                                    <span class="text-gray-400">＋</span>
                                @endif
                            </button>
                        </td>
                        <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.part_no" class="w-24 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="w-full px-2 py-1">
                            <input type="text" wire:model="form.itens.{{ $i }}.description" class="w-full min-w-[20rem] rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" />
                            @if ($item['description_inferred'] ?? false)
                                <span class="mt-0.5 inline-block rounded bg-amber-200 px-1.5 py-0.5 text-[10px] text-amber-900">{{ __('assistant.desc_inferred_badge') }}</span>
                            @endif
                        </td>
                        <td class="px-1 py-1 text-right"><input type="number" wire:model="form.itens.{{ $i }}.quantity" class="w-14 rounded border-gray-300 px-1 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-1 py-1"><input type="text" wire:model="form.itens.{{ $i }}.unit" class="w-12 rounded border-gray-300 px-1 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-1 py-1 text-right"><input type="number" step="0.01" wire:model="form.itens.{{ $i }}.unit_price" class="w-20 rounded border-gray-300 px-1 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1">
                            <select wire:model="form.itens.{{ $i }}.category_id" class="w-32 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10">
                                <option value="">{{ __('assistant.no_category') }}</option>
                                @foreach ($this->importCategoryOptions() as $catId => $catName)
                                    <option value="{{ $catId }}">{{ $catName }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-2 py-1">
                            @if (($item['status'] ?? '') === 'novo')
                                <span class="rounded bg-amber-200 px-1.5 py-0.5 text-[10px] text-amber-900" title="{{ __('assistant.new_product_hint') }}">
                                    {{ __('assistant.status_new') }}
                                </span>
                                <button type="button" x-on:click="linkPanel = (linkPanel === {{ $i }} ? null : {{ $i }}); gallery = null"
                                        class="mt-0.5 block text-[10px] text-primary-600 underline dark:text-primary-400">
                                    {{ __('assistant.link_product') }}
                                </button>
                            @else
                                <span class="rounded bg-green-200 px-1.5 py-0.5 text-[10px] text-green-900" title="{{ __('assistant.existing_product_hint') }}">
                                    {{ __('assistant.status_existing') }}
                                </span>
                                @if (($item['match_source'] ?? null) === 'ai' && ($item['status'] ?? '') !== 'novo')
                                    <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] bg-blue-200 text-blue-900">{{ __('assistant.status_ai_suggested') }}</span>
                                @endif
                                @if (filled($item['product_name'] ?? null))
                                    <span class="mt-0.5 block max-w-[10rem] truncate text-[10px] text-gray-500 dark:text-gray-400" title="{{ $item['product_name'] }}">{{ $item['product_name'] }}</span>
                                @endif
                                <button type="button" wire:click="unlinkItemProduct({{ $i }})"
                                        class="mt-0.5 text-[10px] text-danger-600 underline dark:text-danger-400">
                                    {{ __('assistant.unlink_product') }}
                                </button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Galeria única do pool: aberta ao clicar a foto de um item (gallery = índice do item) --}}
    <div x-show="gallery !== null" x-cloak x-on:photo-uploaded.window="gallery = null"
         class="mt-2 rounded border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">
        <div class="flex flex-wrap gap-2">
            <button type="button" x-on:click="$wire.setItemPhoto(gallery, null); gallery = null"
                    class="flex h-16 w-16 items-center justify-center rounded border border-dashed border-gray-300 text-xs text-gray-500 dark:border-white/15">
                {{ __('assistant.photo_none') }}
            </button>
            {{-- Upload manual: o alvo é setado antes de abrir o seletor de arquivo --}}
            <label x-on:click="$wire.set('photoUploadTarget', gallery)"
                   class="flex h-16 w-16 cursor-pointer items-center justify-center rounded border border-dashed border-primary-400 p-1 text-center text-[10px] leading-tight text-primary-600 dark:border-primary-600 dark:text-primary-300">
                <span wire:loading.remove wire:target="itemPhotoUpload">{{ __('assistant.photo_upload') }}</span>
                <x-filament::loading-indicator class="h-4 w-4" wire:loading wire:target="itemPhotoUpload" />
                <input type="file" accept="image/*" class="hidden" wire:model="itemPhotoUpload" />
            </label>
            @foreach ($importImagePool as $poolEntry)
                <div class="relative">
                    <button type="button" x-on:click="$wire.setItemPhoto(gallery, {{ $poolEntry['id'] }}); gallery = null"
                            class="h-16 w-16 overflow-hidden rounded border border-gray-200 dark:border-white/10">
                        <img src="{{ $this->importImageThumb($poolEntry['id']) }}" class="h-full w-full object-cover" alt="" loading="lazy" />
                    </button>
                    <button type="button" wire:click="flipPoolImage({{ $poolEntry['id'] }})"
                            title="{{ __('assistant.photo_flip') }}"
                            class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full border border-gray-300 bg-white text-[10px] shadow dark:border-white/20 dark:bg-gray-800">
                        ↕
                    </button>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Painel "vincular a produto existente" (aberto pelo botão Vincular… de um item "novo") --}}
    <div x-show="linkPanel !== null" x-cloak x-on:product-linked.window="linkPanel = null"
         class="mt-2 rounded border border-gray-200 bg-gray-50 p-2 dark:border-white/10 dark:bg-white/5">
        <p class="text-xs font-medium">{{ __('assistant.link_product_title') }}</p>
        @if ($this->importSupplierScopeId() !== null)
            <label class="mt-0.5 flex items-center gap-1.5 text-[11px] text-gray-600 dark:text-gray-300">
                <input type="checkbox" wire:model.live="importProductSearchAll" class="rounded border-gray-300 dark:border-white/10" />
                {{ __('assistant.search_all_catalog') }}
            </label>
            <p class="text-[10px] text-gray-500 dark:text-gray-400">
                {{ $importProductSearchAll ? __('assistant.scope_all_hint') : __('assistant.scope_supplier_hint', ['supplier' => $form['fornecedor']['nome'] ?? '']) }}
            </p>
        @endif
        <input type="search" wire:model.live.debounce.400ms="importProductSearch" placeholder="{{ __('assistant.search_product') }}"
               class="mt-1 block w-full rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" />
        <div class="mt-1 flex max-h-40 flex-wrap gap-1 overflow-y-auto">
            @foreach ($this->importProductOptions() as $productId => $productLabel)
                <button type="button" x-on:click="$wire.linkItemProduct(linkPanel, {{ $productId }})"
                        class="rounded border border-gray-300 bg-white px-2 py-0.5 text-xs hover:bg-primary-50 dark:border-white/15 dark:bg-gray-900 dark:hover:bg-white/10">
                    {{ $productLabel }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-3 flex gap-2">
        <x-filament::button wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" color="primary" size="sm">
            {{ __('assistant.confirm_import') }}
        </x-filament::button>
        <x-filament::button wire:click="cancelImport" color="gray" size="sm">
            {{ __('assistant.cancel') }}
        </x-filament::button>
        <x-filament::button wire:click="reopenTargetChooser" color="gray" size="sm" outlined>
            {{ __('assistant.switch_target') }}
        </x-filament::button>
    </div>
</div>
