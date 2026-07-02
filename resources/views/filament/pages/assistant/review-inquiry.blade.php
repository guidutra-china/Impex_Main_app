<div class="rounded-xl border border-primary-300 bg-primary-50 p-4 text-sm dark:border-primary-700 dark:bg-primary-950/40">
    <p class="font-semibold">{{ __('assistant.preview_title') }}</p>
    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ __('assistant.review_hint') }}</p>

    {{-- Resumo --}}
    @php($casados = collect($form['itens'])->where('status', 'existente')->count())
    <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
        {{ __('assistant.summary_inquiry_counts', ['total' => count($form['itens']), 'matched' => $casados, 'unmatched' => count($form['itens']) - $casados]) }}
    </p>

    {{-- Modo: nova vs existente --}}
    @if ($importLockedInquiryId === null)
        <div class="mt-2 flex gap-4 text-xs">
            <label class="flex items-center gap-1">
                <input type="radio" value="nova" wire:model.live="form.modo" />
                {{ __('assistant.mode_new_inquiry') }}
            </label>
            <label class="flex items-center gap-1">
                <input type="radio" value="existente" wire:model.live="form.modo" />
                {{ __('assistant.mode_existing_inquiry') }}
            </label>
        </div>
    @endif

    @if (($form['modo'] ?? 'nova') === 'existente')
        <div class="mt-2">
            <label class="text-xs">{{ __('assistant.inquiry_label') }}
                @if ($importLockedInquiryId !== null)
                    <input type="text" value="{{ $this->lockedInquiryLabel() }}" disabled
                           class="mt-0.5 block w-full rounded border-gray-300 bg-gray-100 text-sm dark:bg-gray-800 dark:border-white/10" />
                @else
                    <input type="search" wire:model.live.debounce.400ms="inquirySearch" placeholder="{{ __('assistant.search_inquiry') }}"
                           class="mb-1 mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
                    <select wire:model="form.inquiry_id" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10">
                        <option value="">{{ __('assistant.select_inquiry') }}</option>
                        @foreach ($this->openInquiryOptions() as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                @endif
            </label>
        </div>
    @else
        {{-- Cabeçalho da inquiry nova --}}
        <div class="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4">
            <label class="col-span-2 text-xs">
                {{ __('assistant.client') }}
                <span class="ml-1 rounded px-1.5 py-0.5 text-[10px] {{ ($form['cliente']['status'] ?? '') === 'novo' ? 'bg-amber-200 text-amber-900' : 'bg-green-200 text-green-900' }}">
                    {{ ($form['cliente']['status'] ?? '') === 'novo' ? __('assistant.status_new') : __('assistant.status_existing') }}
                </span>
                <input type="text" wire:model="form.cliente.nome" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
            </label>
            <label class="text-xs">{{ __('assistant.currency') }}
                <input type="text" wire:model="form.cabecalho.currency_code" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
            </label>
            <label class="text-xs">{{ __('assistant.deadline') }}
                <input type="date" wire:model="form.cabecalho.deadline" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10" />
            </label>
            <label class="col-span-2 text-xs md:col-span-4">{{ __('assistant.notes') }}
                <textarea wire:model="form.cabecalho.notes" rows="2" class="mt-0.5 block w-full rounded border-gray-300 text-sm dark:bg-gray-900 dark:border-white/10"></textarea>
            </label>
        </div>
    @endif

    {{-- Itens editáveis --}}
    <div class="mt-3 max-h-72 overflow-y-auto rounded border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <table class="w-full text-left text-xs">
            <thead class="sticky top-0 bg-gray-50 dark:bg-white/5">
                <tr>
                    <th class="px-2 py-1">{{ __('assistant.col.part_no') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.description') }}</th>
                    <th class="px-2 py-1 text-right">{{ __('assistant.col.qty') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.unit') }}</th>
                    <th class="px-2 py-1 text-right">{{ __('assistant.col_target_price') }}</th>
                    <th class="px-2 py-1">{{ __('assistant.col.status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($form['itens'] as $i => $item)
                    <tr class="border-t border-gray-100 align-top dark:border-white/5" wire:key="inq-item-{{ $i }}">
                        <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.part_no" class="w-24 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.description" class="w-full min-w-[12rem] rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1 text-right"><input type="number" wire:model="form.itens.{{ $i }}.quantity" class="w-16 rounded border-gray-300 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1"><input type="text" wire:model="form.itens.{{ $i }}.unit" class="w-16 rounded border-gray-300 text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1 text-right"><input type="number" step="0.01" wire:model="form.itens.{{ $i }}.target_price" class="w-24 rounded border-gray-300 text-right text-xs dark:bg-gray-900 dark:border-white/10" /></td>
                        <td class="px-2 py-1">
                            <span class="rounded px-1.5 py-0.5 text-[10px] {{ ($item['status'] ?? '') === 'novo' ? 'bg-amber-200 text-amber-900' : 'bg-green-200 text-green-900' }}">
                                {{ ($item['status'] ?? '') === 'novo' ? __('assistant.status_new') : __('assistant.status_existing') }}
                            </span>
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
        @if ($importLockedInquiryId === null)
            <x-filament::button wire:click="reopenTargetChooser" color="gray" size="sm" outlined>
                {{ __('assistant.switch_target') }}
            </x-filament::button>
        @endif
    </div>
</div>
