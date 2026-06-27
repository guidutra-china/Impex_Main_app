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
        @if ($importPreview)
            <div class="rounded-xl border border-primary-300 bg-primary-50 p-4 text-sm dark:border-primary-700 dark:bg-primary-950/40">
                <p class="font-semibold">{{ __('assistant.preview_title') }}</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('assistant.edit_hint') }}</p>
                <p class="mt-1">
                    {{ __('assistant.supplier') }}: <strong>{{ $importPreview['fornecedor']['nome'] }}</strong>
                    <span class="ml-1 rounded px-1.5 py-0.5 text-xs {{ $importPreview['fornecedor']['status'] === 'novo' ? 'bg-amber-200 text-amber-900' : 'bg-green-200 text-green-900' }}">
                        {{ $importPreview['fornecedor']['status'] === 'novo' ? __('assistant.status_new') : __('assistant.status_existing') }}
                    </span>
                </p>
                <p class="mt-1">
                    {{ __('assistant.summary_counts', ['total' => $importPreview['resumo']['total_itens'], 'existing' => $importPreview['resumo']['produtos_existentes'], 'new' => $importPreview['resumo']['produtos_novos']]) }}
                    {{ __('assistant.items_total') }}: <strong>{{ $importPreview['resumo']['total_estimado'] }}</strong>
                    @if (!empty($importPreview['resumo']['documento_total']))
                        · {{ __('assistant.document_total') }}: <strong>{{ $importPreview['resumo']['documento_total'] }}</strong>
                    @endif
                </p>

                @if (!empty($importPreview['resumo']['extras']))
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                        {{ __('assistant.extras_note') }}
                        @foreach ($importPreview['resumo']['extras'] as $extra)
                            <span class="whitespace-nowrap">{{ $extra['descricao'] }} {{ $extra['valor'] }}</span>@if (!$loop->last); @endif
                        @endforeach
                    </p>
                @endif

                @if (!empty($importPreview['resumo']['divergencia']))
                    <p class="mt-2 rounded-lg bg-amber-100 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                        {{ __('assistant.divergence_warning') }}
                    </p>
                @endif

                @if (($importPreview['resumo']['produtos_sem_categoria'] ?? 0) > 0)
                    <p class="mt-2 rounded-lg bg-amber-100 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                        {{ __('assistant.uncategorized_warning', ['count' => $importPreview['resumo']['produtos_sem_categoria']]) }}
                    </p>
                @endif

                @php($previewCurrency = strtoupper($importPreview['cabecalho']['currency_code'] ?? 'USD'))
                <div class="mt-3 max-h-48 overflow-y-auto rounded border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                    <table class="w-full text-left text-xs">
                        <thead class="sticky top-0 bg-gray-50 dark:bg-white/5">
                            <tr>
                                <th class="px-2 py-1">{{ __('assistant.col.part_no') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.description') }}</th>
                                <th class="px-2 py-1 text-right">{{ __('assistant.col.qty') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.unit') }}</th>
                                <th class="px-2 py-1 text-right">{{ __('assistant.col.unit_price') }}</th>
                                <th class="px-2 py-1 text-right">{{ __('assistant.col.total') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.category') }}</th>
                                <th class="px-2 py-1">{{ __('assistant.col.product') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($importPreview['itens'] as $item)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="px-2 py-1">{{ $item['part_no'] }}</td>
                                    <td class="px-2 py-1">{{ \Illuminate\Support\Str::limit($item['description'], 60) }}</td>
                                    <td class="px-2 py-1 text-right">{{ $item['quantity'] }}</td>
                                    <td class="px-2 py-1">{{ $item['unit'] }}</td>
                                    <td class="px-2 py-1 text-right whitespace-nowrap">{{ $previewCurrency }} {{ \App\Domain\Infrastructure\Support\Money::format($item['unit_cost_minor']) }}</td>
                                    <td class="px-2 py-1 text-right whitespace-nowrap">{{ $previewCurrency }} {{ \App\Domain\Infrastructure\Support\Money::format($item['unit_cost_minor'] * $item['quantity']) }}</td>
                                    <td class="px-2 py-1">
                                        @if (!empty($item['category_name']))
                                            {{ $item['category_name'] }}
                                        @else
                                            <span class="rounded bg-amber-200 px-1.5 py-0.5 text-amber-900">{{ __('assistant.no_category') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-2 py-1">{{ $item['status'] === 'novo' ? __('assistant.status_new') : __('assistant.status_existing') }}</td>
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
