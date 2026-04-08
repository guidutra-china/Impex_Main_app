<div
    wire:poll.15s
    x-data="{ previewUrl: null, previewName: null, openPreview(url, name) { this.previewUrl = url; this.previewName = name; }, closePreview() { this.previewUrl = null; this.previewName = null; } }"
    class="flex h-[600px] gap-4 rounded-lg border border-gray-200 bg-white relative"
>
    {{-- Inbox --}}
    <aside class="w-1/3 border-r border-gray-200 overflow-y-auto">
        <header class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-gray-900">{{ __('messaging.conversations') }}</h2>
            <button
                type="button"
                wire:click="openNewConversation"
                class="inline-flex items-center gap-1 rounded-md bg-primary-600 px-2 py-1 text-xs font-semibold text-white hover:bg-primary-500"
            >
                {{ __('messaging.new') }}
            </button>
        </header>

        <ul class="divide-y divide-gray-100">
            @forelse ($this->conversations as $conversation)
                @php
                    $latest = $conversation->latestMessage->first();
                    $isActive = $conversation->id === $activeConversationId;
                @endphp

                <li>
                    <button
                        type="button"
                        wire:click="selectConversation({{ $conversation->id }})"
                        class="block w-full text-left px-4 py-3 hover:bg-gray-50 {{ $isActive ? 'bg-primary-50' : '' }}"
                    >
                        <div class="flex justify-between items-baseline">
                            <p class="text-sm font-medium text-gray-900 truncate">
                                {{ $conversation->subject ?? $conversation->users->where('id', '!=', auth()->id())->pluck('name')->join(', ') }}
                            </p>
                            @if ($latest)
                                <span class="text-xs text-gray-400 ml-2 shrink-0">
                                    {{ $latest->created_at->diffForHumans(short: true) }}
                                </span>
                            @endif
                        </div>
                        @if ($latest)
                            <p class="text-xs text-gray-500 truncate mt-1">{{ \Illuminate\Support\Str::limit($latest->body, 60) }}</p>
                        @endif
                    </button>
                </li>
            @empty
                <li class="px-4 py-6 text-sm text-gray-500 text-center">{{ __('messaging.no_conversations') }}</li>
            @endforelse
        </ul>
    </aside>

    {{-- Thread --}}
    <section class="flex-1 flex flex-col">
        @if ($this->activeConversation)
            <header class="px-4 py-3 border-b border-gray-200">
                <h3 class="text-sm font-semibold text-gray-900">
                    {{ $this->activeConversation->subject ?? $this->activeConversation->users->where('id', '!=', auth()->id())->pluck('name')->join(', ') }}
                </h3>
                <p class="text-xs text-gray-500 mt-0.5">
                    {{ trans_choice('messaging.participants_count', $this->activeConversation->users->count(), ['count' => $this->activeConversation->users->count()]) }}
                </p>

                @if ($this->activeConversation->subjectEntity)
                    @php
                        $entity = $this->activeConversation->subjectEntity;
                        $entityUrl = $this->urlForEntity($entity);
                        $entityRef = $entity->reference ?? class_basename($entity);
                    @endphp
                    <div class="mt-2 flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="toggleEntityPanel"
                            @class([
                                'inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                'bg-info-100 text-info-800 ring-info-600/30' => $entityPanelOpen,
                                'bg-info-50 text-info-700 ring-info-600/20 hover:bg-info-100' => ! $entityPanelOpen,
                            ])
                        >
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg>
                            <span>{{ __('messaging.anchored_to') }}: <strong>{{ $entityRef }}</strong></span>
                            @if ($entityUrl)
                                <span class="text-[10px] opacity-70">{{ $entityPanelOpen ? '—' : '+' }}</span>
                            @endif
                        </button>

                        @if ($entityUrl && $entityPanelOpen)
                            <a
                                href="{{ $entityUrl }}"
                                target="_blank"
                                rel="noopener"
                                class="text-[10px] text-gray-500 hover:text-gray-700 underline"
                                title="{{ __('messaging.open_new_tab') }}"
                            >
                                ↗
                            </a>
                        @endif
                    </div>
                @endif
            </header>

            <div class="flex-1 overflow-y-auto px-4 py-3 space-y-3">
                @foreach ($this->activeConversation->messages as $message)
                    @php
                        $own = $message->sender_id === auth()->id();
                        $display = $this->bodyForDisplay($message);
                        $canTranslate = $this->canTranslate($message);
                    @endphp
                    <div class="flex {{ $own ? 'justify-end' : 'justify-start' }}">
                        <div class="max-w-[70%] rounded-lg px-3 py-2 {{ $own ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-900' }}">
                            @unless ($own)
                                <p class="text-xs font-semibold mb-1">{{ $message->sender->name }}</p>
                            @endunless

                            @if ($display['is_translated'])
                                <p class="text-[10px] {{ $own ? 'text-primary-100' : 'text-gray-500' }} mb-1 flex items-center gap-1">
                                    <span>{{ __('messaging.translated_from', ['locale' => $message->detected_locale]) }}</span>
                                </p>
                            @endif

                            <div class="text-sm whitespace-pre-wrap">
                                {!! $this->renderBody($display['body'], $message->references) !!}
                            </div>

                            @if ($message->documents->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($message->documents as $doc)
                                        @php $previewUrl = $this->previewUrlFor($doc); @endphp
                                        <button
                                            type="button"
                                            @click="openPreview(@js($previewUrl), @js($doc->name))"
                                            class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium {{ $own ? 'bg-primary-500/30 text-white hover:bg-primary-500/50' : 'bg-white text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50' }}"
                                            title="{{ $doc->name }}"
                                        >
                                            <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 10-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                            <span class="truncate max-w-[180px]">{{ $doc->name }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif

                            <div class="flex items-center justify-between gap-2 mt-1">
                                <p class="text-[10px] {{ $own ? 'text-primary-100' : 'text-gray-500' }}">
                                    {{ $message->created_at->format('M d, H:i') }}
                                </p>

                                @if ($canTranslate)
                                    @if ($display['is_translated'])
                                        <button
                                            type="button"
                                            wire:click="showOriginal({{ $message->id }})"
                                            class="text-[10px] underline {{ $own ? 'text-primary-100 hover:text-white' : 'text-primary-700 hover:text-primary-900' }}"
                                        >
                                            {{ __('messaging.show_original') }}
                                        </button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="translateMessage({{ $message->id }})"
                                            class="text-[10px] underline {{ $own ? 'text-primary-100 hover:text-white' : 'text-primary-700 hover:text-primary-900' }}"
                                        >
                                            {{ __('messaging.translate') }}
                                        </button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <footer class="border-t border-gray-200 p-3">
                @if (! empty($attachments))
                    <div class="mb-2 flex flex-wrap gap-1">
                        @foreach ($attachments as $index => $tmp)
                            <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-0.5 text-xs text-gray-700 ring-1 ring-inset ring-gray-300">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 10-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                <span class="truncate max-w-[180px]">{{ $tmp->getClientOriginalName() }}</span>
                                <button type="button" wire:click="removeAttachment({{ $index }})" class="text-gray-400 hover:text-gray-600">&times;</button>
                            </span>
                        @endforeach
                    </div>
                @endif

                <form wire:submit.prevent="send" class="flex gap-2 relative">
                    <div
                        class="flex-1 relative"
                        x-data="{
                            open: false,
                            results: [],
                            highlight: 0,
                            tokenStart: -1,
                            async detect(el) {
                                const value = el.value;
                                const cursor = el.selectionStart;
                                const upToCursor = value.slice(0, cursor);
                                const match = upToCursor.match(/@([A-Za-z0-9-]*)$/);
                                if (! match) {
                                    this.open = false;
                                    this.tokenStart = -1;
                                    return;
                                }
                                this.tokenStart = cursor - match[0].length;
                                const query = match[1];
                                if (query.length < 1) {
                                    this.results = [];
                                    this.open = true;
                                    return;
                                }
                                this.results = await $wire.searchReferenceables(query);
                                this.highlight = 0;
                                this.open = this.results.length > 0;
                            },
                            insert(item) {
                                const el = $refs.input;
                                const value = el.value;
                                const cursor = el.selectionStart;
                                const before = value.slice(0, this.tokenStart);
                                const after = value.slice(cursor);
                                const token = '@' + item.code + ' ';
                                const next = before + token + after;
                                $wire.set('newMessage', next);
                                this.open = false;
                                this.results = [];
                                $nextTick(() => {
                                    el.focus();
                                    const pos = (before + token).length;
                                    el.setSelectionRange(pos, pos);
                                });
                            },
                            move(delta) {
                                if (! this.open || this.results.length === 0) return;
                                this.highlight = (this.highlight + delta + this.results.length) % this.results.length;
                            },
                        }"
                        @click.outside="open = false"
                    >
                        <input
                            x-ref="input"
                            type="text"
                            wire:model="newMessage"
                            @input="detect($event.target)"
                            @keydown.arrow-down.prevent="move(1)"
                            @keydown.arrow-up.prevent="move(-1)"
                            @keydown.enter="if (open && results.length > 0) { $event.preventDefault(); insert(results[highlight]); }"
                            @keydown.escape="open = false"
                            placeholder="{{ __('messaging.compose_placeholder') }}"
                            class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                            autocomplete="off"
                        />

                        <div
                            x-show="open && results.length > 0"
                            x-cloak
                            class="absolute bottom-full left-0 mb-1 w-full max-w-md rounded-md border border-gray-200 bg-white shadow-lg z-20 max-h-60 overflow-y-auto"
                        >
                            <template x-for="(item, index) in results" :key="item.code">
                                <button
                                    type="button"
                                    @click="insert(item)"
                                    @mouseenter="highlight = index"
                                    :class="highlight === index ? 'bg-primary-50' : ''"
                                    class="w-full text-left px-3 py-2 text-sm hover:bg-primary-50 flex justify-between gap-2"
                                >
                                    <span class="font-mono text-xs text-primary-700" x-text="item.code"></span>
                                    <span class="text-gray-600 truncate" x-text="item.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <label
                        class="inline-flex items-center justify-center rounded-md bg-gray-100 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-200 cursor-pointer"
                        title="{{ __('messaging.attach_file') }}"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 10-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                        <input type="file" wire:model="attachments" multiple class="hidden" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif">
                    </label>

                    <button
                        type="submit"
                        class="inline-flex items-center rounded-md bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                    >
                        {{ __('messaging.send') }}
                    </button>
                </form>

                <div wire:loading wire:target="attachments" class="mt-1 text-xs text-gray-500">
                    {{ __('messaging.uploading') }}
                </div>
            </footer>
        @else
            <div class="flex-1 flex items-center justify-center text-sm text-gray-500">
                {{ __('messaging.select_conversation') }}
            </div>
        @endif
    </section>

    {{-- Entity Side Panel (anchored document viewer) --}}
    @if ($entityPanelOpen && $this->activeConversation && $this->activeConversation->subjectEntity)
        @php
            $panelEntity = $this->activeConversation->subjectEntity;
            $panelEntityUrl = $this->urlForEntity($panelEntity);
            $panelEntityRef = $panelEntity->reference ?? class_basename($panelEntity);
        @endphp
        <aside class="w-1/2 min-w-[400px] border-l border-gray-200 flex flex-col bg-gray-50">
            <header class="px-4 py-3 border-b border-gray-200 flex items-center justify-between bg-white">
                <div class="min-w-0">
                    <p class="text-[10px] uppercase tracking-wide text-gray-500">{{ __('messaging.anchored_to') }}</p>
                    <h3 class="text-sm font-semibold text-gray-900 truncate">{{ $panelEntityRef }}</h3>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    @if ($panelEntityUrl)
                        <a
                            href="{{ $panelEntityUrl }}"
                            target="_blank"
                            rel="noopener"
                            class="text-xs text-primary-600 hover:text-primary-800 underline"
                        >
                            {{ __('messaging.open_new_tab') }}
                        </a>
                    @endif
                    <button type="button" wire:click="toggleEntityPanel" class="text-gray-400 hover:text-gray-600 text-lg leading-none" title="{{ __('messaging.close_panel') }}">&times;</button>
                </div>
            </header>

            @if ($panelEntityUrl)
                <iframe
                    src="{{ $panelEntityUrl }}"
                    class="flex-1 w-full"
                    frameborder="0"
                    loading="lazy"
                ></iframe>
            @else
                <div class="flex-1 flex items-center justify-center p-4 text-sm text-gray-500">
                    {{ __('messaging.entity_not_available') }}
                </div>
            @endif
        </aside>
    @endif

    {{-- Document Preview Modal --}}
    <div
        x-show="previewUrl !== null"
        x-cloak
        @keydown.escape.window="closePreview()"
        class="absolute inset-0 z-40 flex items-center justify-center bg-gray-900/70"
    >
        <div class="relative h-[90%] w-[90%] rounded-lg bg-white shadow-xl flex flex-col">
            <header class="flex items-center justify-between px-4 py-2 border-b border-gray-200">
                <h3 class="text-sm font-semibold text-gray-900 truncate" x-text="previewName"></h3>
                <div class="flex items-center gap-2">
                    <a
                        :href="previewUrl"
                        target="_blank"
                        rel="noopener"
                        class="text-xs text-primary-600 hover:text-primary-800 underline"
                    >
                        {{ __('messaging.open_new_tab') }}
                    </a>
                    <button type="button" @click="closePreview()" class="text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
                </div>
            </header>
            <iframe
                :src="previewUrl"
                class="flex-1 w-full rounded-b-lg"
                frameborder="0"
                x-show="previewUrl !== null"
            ></iframe>
        </div>
    </div>

    {{-- New Conversation Modal --}}
    @if ($showNewConversationModal)
        <div class="absolute inset-0 z-30 flex items-center justify-center bg-gray-900/40">
            <div class="w-full max-w-md rounded-lg bg-white shadow-xl">
                <header class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">{{ __('messaging.new_conversation') }}</h3>
                    <button type="button" wire:click="closeNewConversation" class="text-gray-400 hover:text-gray-600">&times;</button>
                </header>

                <div class="p-4 space-y-4">
                    @if ($discussEntityLabel)
                        <div class="rounded-md bg-info-50 px-3 py-2 text-xs text-info-800 ring-1 ring-inset ring-info-600/20">
                            {{ __('messaging.anchored_to') }}: <strong>{{ $discussEntityLabel }}</strong>
                        </div>
                    @endif

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">{{ __('messaging.subject_optional') }}</label>
                        <input
                            type="text"
                            wire:model="newConversationSubject"
                            placeholder="{{ __('messaging.subject_placeholder') }}"
                            class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">{{ __('messaging.participants') }}</label>

                        @if ($this->selectedUsers->isNotEmpty())
                            <div class="flex flex-wrap gap-1 mb-2">
                                @foreach ($this->selectedUsers as $user)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-primary-50 px-2 py-0.5 text-xs text-primary-700 ring-1 ring-inset ring-primary-600/20">
                                        {{ $user->name }}
                                        <button type="button" wire:click="removeParticipant({{ $user->id }})" class="text-primary-500 hover:text-primary-700">&times;</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <input
                            type="text"
                            wire:model.live.debounce.250ms="userSearch"
                            placeholder="{{ __('messaging.search_users_placeholder') }}"
                            class="w-full rounded-md border-gray-300 text-sm focus:border-primary-500 focus:ring-primary-500"
                            autocomplete="off"
                        />

                        @if ($this->userSearchResults->isNotEmpty())
                            <ul class="mt-1 max-h-48 overflow-y-auto rounded-md border border-gray-200 divide-y divide-gray-100">
                                @foreach ($this->userSearchResults as $user)
                                    <li>
                                        <button
                                            type="button"
                                            wire:click="addParticipant({{ $user->id }})"
                                            class="w-full text-left px-3 py-2 text-sm hover:bg-primary-50 flex justify-between gap-2"
                                        >
                                            <span class="text-gray-900">{{ $user->name }}</span>
                                            <span class="text-xs text-gray-500">{{ $user->email }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (strlen(trim($userSearch)) > 0)
                            <p class="mt-1 text-xs text-gray-500">{{ __('messaging.no_users_found') }}</p>
                        @endif
                    </div>
                </div>

                <footer class="px-4 py-3 border-t border-gray-200 flex justify-end gap-2">
                    <button type="button" wire:click="closeNewConversation" class="rounded-md border border-gray-300 px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50">
                        {{ __('messaging.cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="createConversation"
                        @class([
                            'rounded-md px-3 py-1.5 text-sm font-semibold text-white',
                            'bg-primary-600 hover:bg-primary-500' => count($selectedUserIds) > 0,
                            'bg-gray-300 cursor-not-allowed' => count($selectedUserIds) === 0,
                        ])
                        @disabled(count($selectedUserIds) === 0)
                    >
                        {{ __('messaging.create') }}
                    </button>
                </footer>
            </div>
        </div>
    @endif
</div>
