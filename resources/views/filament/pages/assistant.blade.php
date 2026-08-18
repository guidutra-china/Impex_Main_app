<x-filament-panels::page>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-4">
        {{-- Import universal: revisão / escolha de destino / dropzone --}}
        @if ($form)
            @include('filament.pages.assistant.review-'.$importTargetKey)
        @elseif ($importSuggestion !== null && $importFilePath !== null)
            {{-- Escolha do destino após a classificação --}}
            <div class="rounded-xl border border-primary-300 bg-primary-50 p-4 text-sm dark:border-primary-700 dark:bg-primary-950/40">
                <p class="font-semibold">{{ __('assistant.choose_target') }}</p>
                @php($targetOptions = $this->importTargetOptions())
                <div class="mt-2 flex flex-wrap gap-2" wire:loading.remove wire:target="chooseImportTarget">
                    @foreach ($targetOptions as $key => $option)
                        @if ($option['available'])
                            <x-filament::button
                                wire:click="chooseImportTarget('{{ $key }}')"
                                :color="$key === ($importSuggestion['tipo'] ?? null) ? 'primary' : 'gray'"
                                size="sm"
                            >
                                {{ __('assistant.import_as', ['label' => $option['label']]) }}
                            </x-filament::button>
                        @else
                            <x-filament::button
                                disabled
                                color="gray"
                                size="sm"
                                outlined
                                title="{{ __('assistant.target_unavailable_permission', ['label' => $option['label']]) }}"
                            >
                                {{ __('assistant.import_as', ['label' => $option['label']]) }} 🔒
                            </x-filament::button>
                        @endif
                    @endforeach
                    {{-- Atalho combinado: SQ + Inquiry vinculada num passo só (documento de fornecedor enviado por um cliente) --}}
                    @if (($targetOptions['supplier_quotation']['available'] ?? false) && $importLockedInquiryId === null && auth()->user()->can('create-inquiries'))
                        <x-filament::button
                            wire:click="chooseImportTarget('supplier_quotation_with_inquiry')"
                            color="gray"
                            size="sm"
                        >
                            {{ __('assistant.import_as_sq_with_inquiry') }}
                        </x-filament::button>
                    @endif
                    <x-filament::button wire:click="cancelImport" color="danger" size="sm" outlined>
                        {{ __('assistant.cancel') }}
                    </x-filament::button>
                </div>
                <div wire:loading.flex wire:target="chooseImportTarget" class="mt-2 items-center gap-2 text-sm text-gray-500">
                    <x-filament::loading-indicator class="h-4 w-4" />
                    <span>{{ __('assistant.processing') }}</span>
                </div>
            </div>
        @else
            {{-- Zona de arrastar e soltar para importar um documento --}}
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

        @if (count($messages) > 0)
            <div class="flex justify-end">
                <x-filament::link tag="button" wire:click="clearConversation" color="gray" size="sm">
                    {{ __('assistant.clear_conversation') }}
                </x-filament::link>
            </div>
        @endif
    </div>
</x-filament-panels::page>
