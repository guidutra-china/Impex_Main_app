<x-filament-panels::page>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        {{-- Transcript --}}
        <div
            class="flex min-h-[50vh] max-h-[65vh] flex-col gap-3 overflow-y-auto rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
            x-data
            x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
            x-on:assistant-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
        >
            @forelse ($messages as $message)
                <div @class([
                    'max-w-[80%] whitespace-pre-wrap rounded-xl px-4 py-2 text-sm',
                    'self-end bg-primary-600 text-white' => $message['role'] === 'user',
                    'self-start bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-gray-100' => $message['role'] === 'assistant',
                ])>
                    {{ $message['text'] }}
                </div>
            @empty
                <p class="m-auto max-w-sm text-center text-sm text-gray-400">
                    {{ __('assistant.empty_hint') }}
                </p>
            @endforelse

            <div
                wire:loading
                wire:target="send"
                class="self-start rounded-xl bg-gray-100 px-4 py-2 text-sm text-gray-500 dark:bg-white/10"
            >
                {{ __('assistant.thinking') }}
            </div>
        </div>

        {{-- Importar cotação de fornecedor por arquivo --}}
        @if ($form)
            @php($previewCurrency = strtoupper($form['cabecalho']['currency_code'] ?? 'USD'))
            <div class="rounded-xl border border-primary-300 bg-primary-50 p-4 text-sm dark:border-primary-700 dark:bg-primary-950/40"
                 x-data="{ gallery: null }">
                <p class="font-semibold">{{ __('assistant.preview_title') }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('assistant.review_hint') }}</p>

                {{-- Fornecedor --}}
                <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
                    <label class="col-span-2 text-xs">{{ __('assistant.supplier') }}
                        <input type="text" wire:model="form.fornecedor.nome" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
                    </label>
                    <label class="text-xs">{{ __('assistant.currency') }}
                        <input type="text" wire:model="form.cabecalho.currency_code" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
                    </label>
                    <label class="text-xs">Incoterm
                        <input type="text" wire:model="form.cabecalho.incoterm" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
                    </label>
                </div>

                {{-- Itens editáveis --}}
                <div class="mt-3 max-h-72 overflow-y-auto rounded border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                    <table class="w-full text-left text-xs">
                        <thead class="sticky top-0 bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-2 py-1">{{ __('assistant.col.photo') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.part_no') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.description') }}</th>
                                <th class="px-2 py-1 text-right">{{ __('assistant.col.qty') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.unit') }}</th>
                                <th class="px-2 py-1 text-right">{{ __('assistant.col.unit_price') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.category') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($form['itens'] as $i => $item)
                                <tr class="border-t border-gray-100 align-top dark:border-white/5" wire:key="item-{{ $i }}">
                                    <td class="px-2 py-1">
                                        @php($thumb = $item['photo_index'] !== null ? $this->importImageThumb($item['photo_index']) : null)
                                        <button type="button" x-on:click="gallery = (gallery === {{ $i }} ? null : {{ $i }})"
                                                class="flex h-12 w-12 items-center justify-center overflow-hidden rounded border border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                                            @if ($thumb)
                                                <img src="{{ $thumb }}" class="h-full w-full object-cover" alt="" />
                                            @else
                                                <span class="text-gray-400">＋</span>
                                            @endif
                                        </button>
                                    </td>
                                    <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.part_no" class="w-24 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                                    <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.description" class="w-full min-w-[12rem] rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                                    <td class="px-2 py-1 text-right"><input type="number" wire:model="form.itens.{{ $i }}.quantity" class="w-16 rounded border-gray-300 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                                    <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.unit" class="w-16 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                                    <td class="px-2 py-1 text-right"><input type="number" step="0.01" wire:model="form.itens.{{ $i }}.unit_price" class="w-24 rounded border-gray-300 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                                    <td class="px-2 py-1">
                                        <select wire:model="form.itens.{{ $i }}.category_id" class="w-32 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10">
                                            <option value="">{{ __('assistant.no_category') }}</option>
                                            @foreach ($this->importCategoryOptions() as $catId => $catName)
                                                <option value="{{ $catId }}">{{ $catName }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                </tr>
                                {{-- Galeria do pool para este item --}}
                                <tr x-show="gallery === {{ $i }}" x-cloak wire:key="gal-{{ $i }}">
                                    <td colspan="7" class="bg-gray-50 px-2 py-2 dark:bg-white/5">
                                        <div class="flex flex-wrap gap-2">
                                            <button type="button" wire:click="setItemPhoto({{ $i }}, null)" x-on:click="gallery = null"
                                                    class="flex h-16 w-16 items-center justify-center rounded border border-dashed border-gray-300 text-xs text-gray-500 dark:border-white/15">
                                                {{ __('assistant.photo_none') }}
                                            </button>
                                            @foreach ($importImagePool as $poolEntry)
                                                <button type="button" wire:click="setItemPhoto({{ $i }}, {{ $poolEntry['id'] }})" x-on:click="gallery = null"
                                                        class="h-16 w-16 overflow-hidden rounded border {{ $item['photo_index'] === $poolEntry['id'] ? 'border-primary-500 ring-2 ring-primary-400' : 'border-gray-200 dark:border-white/10' }}">
                                                    <img src="{{ $this->importImageThumb($poolEntry['id']) }}" class="h-full w-full object-cover" alt="" />
                                                </button>
                                            @endforeach
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 flex gap-2">
                    <x-filament::button wire:click="confirmImport" wire:loading.attr="disabled" wire:target="confirmImport" color="primary" size="sm">
                        {{ __('assistant.confirm_import') }}
                    </x-filament::button>
                    <x-filament::button wire:click="cancelImport" color="gray" size="sm">
                        {{ __('assistant.cancel') }}
                    </x-filament::button>
                </div>
            </div>
        @else
            {{-- Zona de arrastar e soltar para importar cotação de fornecedor --}}
            <div
                x-data="{ dragging: false, fileName: '' }"
                x-on:dragover.prevent="dragging = true"
                x-on:dragenter.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="
                    dragging = false;
                    if ($event.dataTransfer.files.length) {
                        $refs.input.files = $event.dataTransfer.files;
                        fileName = $refs.input.files[0].name;
                        $refs.input.dispatchEvent(new Event('change'));
                    }
                "
                x-on:livewire-upload-finish="$wire.submitImport()"
                x-on:click="$refs.input.click()"
                role="button"
                tabindex="0"
                x-on:keydown.enter="$refs.input.click()"
                :class="dragging
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/40'
                    : 'border-gray-300 hover:border-gray-400 dark:border-white/15 dark:hover:border-white/25'"
                class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed px-4 py-6 text-center transition"
            >
                <input
                    type="file"
                    wire:model="upload"
                    x-ref="input"
                    accept=".xlsx,.xls,.pdf"
                    class="hidden"
                    x-on:change="fileName = $refs.input.files.length ? $refs.input.files[0].name : ''"
                />

                {{-- Estado: enviando / lendo --}}
                <div wire:loading.flex wire:target="upload,submitImport" class="items-center gap-2 text-sm text-gray-500">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    <span x-text="fileName ? ('{{ __('assistant.processing') }} ' + fileName) : '{{ __('assistant.processing') }}'"></span>
                </div>

                {{-- Estado: ocioso --}}
                <div wire:loading.remove wire:target="upload,submitImport" class="flex flex-col items-center gap-1">
                    <x-filament::icon icon="heroicon-o-arrow-up-tray" class="h-6 w-6 text-gray-400" />
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        <span class="font-medium text-primary-600 dark:text-primary-400">{{ __('assistant.dropzone_drag') }}</span> {{ __('assistant.dropzone_rest') }}
                    </p>
                    <p class="text-xs text-gray-400">{{ __('assistant.dropzone_formats') }}</p>
                </div>
            </div>

            @error('upload')
                <p class="text-xs text-danger-600">{{ $message }}</p>
            @enderror
        @endif

        {{-- Composer --}}
        <form wire:submit="send" class="flex items-end gap-2">
            <textarea
                wire:model="draft"
                rows="2"
                placeholder="{{ __('assistant.composer_placeholder') }}"
                wire:keydown.enter.prevent="send"
                class="block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-gray-900 dark:text-gray-100"
            ></textarea>

            <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="send" icon="heroicon-m-paper-airplane">
                {{ __('assistant.send') }}
            </x-filament::button>
        </form>

        @if (count($messages) > 0)
            <div class="flex justify-end">
                <x-filament::link tag="button" wire:click="clearConversation" color="gray" size="sm">
                    {{ __('assistant.clear_conversation') }}
                </x-filament::link>
            </div>
        @endif
    </div>
</x-filament-panels::page>
