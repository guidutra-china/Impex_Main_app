<?php

namespace App\Livewire\Messaging;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Services\MessageTranslator;
use App\Domain\Messaging\Services\MessagingService;
use App\Domain\Messaging\Services\ReferenceResolver;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Inbox + thread pane component, mountable in any Filament panel.
 * MVP: no real-time. Polling refresh every 10 seconds via wire:poll.
 */
class MessagingCenter extends Component
{
    use WithFileUploads;

    public ?int $activeConversationId = null;
    public string $newMessage = '';

    /** Toggle for the anchored-entity side panel (iframe of the Filament resource). */
    public bool $entityPanelOpen = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $attachments = [];

    /** Per-message translation toggle: [messageId => true] when user clicked "Translate" */
    public array $translatedMessageIds = [];

    /** New conversation modal state */
    public bool $showNewConversationModal = false;
    public string $newConversationSubject = '';
    public string $userSearch = '';
    /** @var array<int, int> */
    public array $selectedUserIds = [];

    /** Anchored entity for the conversation being created (only set during "Discuss X" flow). */
    public ?string $discussEntityType = null;
    public ?int $discussEntityId = null;
    public ?string $discussEntityLabel = null;

    public function mount(?int $conversationId = null): void
    {
        // Accept explicit conversation id OR from query string.
        $conversationId = $conversationId ?: (int) request()->query('conversation') ?: null;
        if ($conversationId) {
            $this->activeConversationId = (int) $conversationId;
        }

        // "Discuss this entity" entry point from Filament resource pages.
        $entityType = request()->query('discuss_entity_type');
        $entityId = (int) request()->query('discuss_entity_id');
        if ($entityType && $entityId) {
            $this->openDiscussFlow($entityType, $entityId);
        }
    }

    /**
     * Entry point from Filament "Discuss" header actions.
     *
     * If an existing conversation is anchored to this entity and the user is
     * a participant, it is selected directly. Otherwise the new-conversation
     * modal is opened with the entity pre-anchored and sensible participants
     * suggested from the entity context.
     */
    public function openDiscussFlow(string $entityType, int $entityId): void
    {
        if (! class_exists($entityType)) {
            return;
        }

        /** @var Model|null $entity */
        $entity = $entityType::find($entityId);
        if (! $entity || ! Gate::allows('view', $entity)) {
            return;
        }

        $existing = Conversation::query()
            ->forUser(auth()->user())
            ->where('subject_entity_type', $entityType)
            ->where('subject_entity_id', $entityId)
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            $this->selectConversation($existing->id);

            return;
        }

        $this->discussEntityType = $entityType;
        $this->discussEntityId = $entityId;
        $this->discussEntityLabel = $entity->reference ?? $this->labelFor($entity);

        $companyName = (method_exists($entity, 'company') && $entity->company)
            ? $entity->company->name
            : null;

        $this->showNewConversationModal = true;
        $this->newConversationSubject = $companyName
            ? "{$this->discussEntityLabel} — {$companyName}"
            : $this->discussEntityLabel;
        $this->userSearch = '';
        $this->selectedUserIds = $this->suggestParticipantsFor($entity);
        unset($this->selectedUsers, $this->userSearchResults);
    }

    /**
     * Suggest participants for a new conversation anchored to $entity.
     *
     * Heuristic:
     *   - Internal responsible user (created_by / responsible_user_id)
     *   - External users belonging to $entity->company (client or supplier)
     *
     * Excludes the current user. Returns a de-duplicated array of ids.
     *
     * @return array<int, int>
     */
    private function suggestParticipantsFor(Model $entity): array
    {
        $ids = [];

        foreach (['responsible_user_id', 'created_by'] as $attr) {
            if (! empty($entity->{$attr})) {
                $ids[] = (int) $entity->{$attr};
            }
        }

        if (method_exists($entity, 'company') && $entity->company) {
            $companyId = $entity->company->getKey();
            $companyUsers = User::query()
                ->where('company_id', $companyId)
                ->where('status', 'active')
                ->pluck('id')
                ->all();
            $ids = array_merge($ids, $companyUsers);
        }

        return array_values(array_unique(array_filter(
            $ids,
            fn ($id) => (int) $id !== (int) auth()->id(),
        )));
    }

    #[Computed]
    public function conversations(): Collection
    {
        return Conversation::query()
            ->forUser(auth()->user())
            ->with(['latestMessage', 'users'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function activeConversation(): ?Conversation
    {
        if (! $this->activeConversationId) {
            return null;
        }

        $conversation = Conversation::query()
            ->with([
                'messages' => fn ($q) => $q->orderBy('created_at')->with(['sender', 'references.referenceable', 'translations', 'documents']),
                'users',
                'subjectEntity',
            ])
            ->find($this->activeConversationId);

        if (! $conversation || ! Gate::allows('view', $conversation)) {
            return null;
        }

        return $conversation;
    }

    public function selectConversation(int $conversationId): void
    {
        $conversation = Conversation::find($conversationId);
        if (! $conversation || ! Gate::allows('view', $conversation)) {
            return;
        }

        $this->activeConversationId = $conversationId;
        $this->translatedMessageIds = [];
        $this->entityPanelOpen = false;
        app(MessagingService::class)->markAsRead($conversation, auth()->user());
        unset($this->conversations, $this->activeConversation);
    }

    /**
     * Toggle the side panel that embeds the anchored entity's Filament page.
     * No-op if the active conversation has no subject_entity.
     */
    public function toggleEntityPanel(): void
    {
        $conversation = $this->activeConversation;
        if (! $conversation || ! $conversation->subjectEntity) {
            return;
        }

        $this->entityPanelOpen = ! $this->entityPanelOpen;
    }

    /**
     * Translate a single message into the current user's locale (or fall back
     * to 'en' if the user has no locale). Caches via MessageTranslator so the
     * second click on the same message is free.
     */
    public function translateMessage(int $messageId, MessageTranslator $translator): void
    {
        /** @var Message|null $message */
        $message = Message::with('conversation')->find($messageId);
        if (! $message || ! Gate::allows('view', $message->conversation)) {
            return;
        }

        $targetLocale = auth()->user()->locale ?: 'en';
        $result = $translator->translateForLocale($message, $targetLocale);

        if ($result === null) {
            Notification::make()
                ->title(__('messaging.translation_failed'))
                ->warning()
                ->send();

            return;
        }

        $this->translatedMessageIds[$messageId] = true;
        unset($this->activeConversation);
    }

    public function showOriginal(int $messageId): void
    {
        unset($this->translatedMessageIds[$messageId]);
    }

    /**
     * Dynamic Echo listeners — subscribed only when a conversation is active.
     * The browser-side `echo-private:conversation.{id},MessageSent` event is
     * mapped to a server method that re-renders the thread (re-applying
     * policies). Payload is intentionally ignored — we re-fetch from DB.
     */
    public function getListeners(): array
    {
        if (! $this->activeConversationId) {
            return [];
        }

        return [
            "echo-private:conversation.{$this->activeConversationId},MessageSent" => 'onBroadcastReceived',
        ];
    }

    public function onBroadcastReceived(): void
    {
        // Reset memoized computed props so the next render re-queries the DB.
        unset($this->conversations, $this->activeConversation);
    }

    // --- New conversation flow ---

    public function openNewConversation(): void
    {
        $this->showNewConversationModal = true;
        $this->newConversationSubject = '';
        $this->userSearch = '';
        $this->selectedUserIds = [];
        $this->discussEntityType = null;
        $this->discussEntityId = null;
        $this->discussEntityLabel = null;
    }

    public function closeNewConversation(): void
    {
        $this->showNewConversationModal = false;
        $this->discussEntityType = null;
        $this->discussEntityId = null;
        $this->discussEntityLabel = null;
    }

    /**
     * Users matching the current search, excluding self and already-selected.
     * Cap at 10 to keep the list snappy.
     */
    #[Computed]
    public function userSearchResults(): Collection
    {
        $term = trim($this->userSearch);
        if (strlen($term) < 1) {
            return collect();
        }

        return User::query()
            ->where('id', '!=', auth()->id())
            ->where('status', 'active')
            ->whereNotIn('id', $this->selectedUserIds)
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                  ->orWhere('email', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email', 'type']);
    }

    /**
     * Resolved User models for the chips above the search input.
     */
    #[Computed]
    public function selectedUsers(): Collection
    {
        if (empty($this->selectedUserIds)) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $this->selectedUserIds)
            ->get(['id', 'name', 'email', 'type']);
    }

    public function addParticipant(int $userId): void
    {
        if (in_array($userId, $this->selectedUserIds, true)) {
            return;
        }
        $this->selectedUserIds[] = $userId;
        $this->userSearch = '';
        unset($this->userSearchResults, $this->selectedUsers);
    }

    public function removeParticipant(int $userId): void
    {
        $this->selectedUserIds = array_values(array_filter(
            $this->selectedUserIds,
            fn ($id) => $id !== $userId,
        ));
        unset($this->selectedUsers, $this->userSearchResults);
    }

    public function createConversation(MessagingService $service): void
    {
        if (empty($this->selectedUserIds)) {
            Notification::make()
                ->title(__('messaging.pick_at_least_one'))
                ->warning()
                ->send();

            return;
        }

        $subjectEntity = null;
        if ($this->discussEntityType && $this->discussEntityId && class_exists($this->discussEntityType)) {
            $subjectEntity = $this->discussEntityType::find($this->discussEntityId);
            if ($subjectEntity && ! Gate::allows('view', $subjectEntity)) {
                $subjectEntity = null;
            }
        }

        $conversation = $service->createConversation(
            creator: auth()->user(),
            participantUserIds: $this->selectedUserIds,
            subject: trim($this->newConversationSubject) ?: null,
            type: $subjectEntity ? 'entity_thread' : (count($this->selectedUserIds) > 1 ? 'group' : 'direct'),
            subjectEntity: $subjectEntity,
        );

        $this->showNewConversationModal = false;
        $this->newConversationSubject = '';
        $this->userSearch = '';
        $this->selectedUserIds = [];
        $this->discussEntityType = null;
        $this->discussEntityId = null;
        $this->discussEntityLabel = null;

        $this->selectConversation($conversation->id);
    }

    public function send(MessagingService $service): void
    {
        $conversation = $this->activeConversation;
        if (! $conversation) {
            return;
        }

        $body = trim($this->newMessage);
        $hasAttachments = ! empty($this->attachments);

        if ($body === '' && ! $hasAttachments) {
            return;
        }

        // Rate-limit / basic validation on uploads. Keep the cap generous but
        // enforced to prevent abuse. Per-file: 10 MB. Up to 5 files / message.
        if ($hasAttachments) {
            $this->validate([
                'attachments'   => 'array|max:5',
                'attachments.*' => 'file|max:10240', // 10 MB
            ]);
        }

        try {
            // Livewire's TemporaryUploadedFile extends UploadedFile, so we can
            // pass them directly — the service only checks for UploadedFile.
            $service->sendMessage(
                conversation: $conversation,
                sender: auth()->user(),
                body: $body,
                attachments: $hasAttachments ? array_values($this->attachments) : [],
            );
        } catch (\Throwable $e) {
            Notification::make()
                ->title(__('messaging.send_failed'))
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->newMessage = '';
        $this->attachments = [];
        unset($this->conversations, $this->activeConversation);
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attachments[$index]);
        $this->attachments = array_values($this->attachments);
    }

    /**
     * Mint a signed temporary URL for previewing a message attachment.
     * The URL is valid for 5 minutes and — on top of the HMAC check —
     * the controller also verifies the viewer is a conversation participant.
     */
    public function previewUrlFor(Document $document): string
    {
        return URL::temporarySignedRoute(
            'messaging.documents.preview',
            now()->addMinutes(5),
            ['document' => $document->id],
        );
    }

    /**
     * Search referenceable entities for the @ autocomplete dropdown.
     * Returns up to 8 entities the current user is allowed to view, matching
     * either the reference suffix or any reference fragment.
     *
     * @return array<int, array{code: string, type: string, label: string}>
     */
    public function searchReferenceables(string $query): array
    {
        $query = trim($query);
        if (strlen($query) < 1) {
            return [];
        }

        $results = [];

        foreach (ReferenceResolver::MODEL_MAP as $type => $modelClass) {
            /** @var Model $modelClass */
            $matches = $modelClass::query()
                ->where('reference', 'like', "%{$query}%")
                ->orderByDesc('id')
                ->limit(8)
                ->get();

            foreach ($matches as $model) {
                if (! Gate::allows('view', $model)) {
                    continue;
                }

                $results[] = [
                    'code'  => $model->reference,
                    'type'  => $type,
                    'label' => $this->labelFor($model),
                ];

                if (count($results) >= 8) {
                    return $results;
                }
            }
        }

        return $results;
    }

    private function labelFor(Model $model): string
    {
        // Try common humanizing accessors without coupling to a specific contract.
        foreach (['title', 'name', 'subject'] as $attr) {
            if (! empty($model->{$attr})) {
                return (string) $model->{$attr};
            }
        }

        if (method_exists($model, 'company') && $model->company) {
            return (string) ($model->company->name ?? '');
        }

        return $model->reference ?? '';
    }

    /**
     * Resolve which body to display for a given message, honoring the per-user
     * translation toggle. Returns ['body' => string, 'is_translated' => bool].
     *
     * @return array{body: string, is_translated: bool}
     */
    public function bodyForDisplay(Message $message): array
    {
        if (! isset($this->translatedMessageIds[$message->id])) {
            return ['body' => $message->body, 'is_translated' => false];
        }

        $targetLocale = auth()->user()->locale ?: 'en';
        $cached = $message->translations->firstWhere('locale', $targetLocale);

        return $cached
            ? ['body' => $cached->body, 'is_translated' => true]
            : ['body' => $message->body, 'is_translated' => false];
    }

    /**
     * Whether the Translate button should be shown for a given message.
     * Hides when the message is already in the user's locale OR when language
     * detection couldn't determine the source.
     */
    public function canTranslate(Message $message): bool
    {
        if ($message->detected_locale === null) {
            return false;
        }

        $userLocale = auth()->user()->locale ?: 'en';

        return $message->detected_locale !== $userLocale;
    }

    /**
     * Resolve the Filament deep-link URL for a given entity model in the
     * current panel. Returns null when there is no resource for that model.
     */
    public function urlForEntity(Model $entity): ?string
    {
        try {
            $resource = filament()->getModelResource($entity::class);
        } catch (\Throwable) {
            return null;
        }

        if (! $resource) {
            return null;
        }

        $pages = $resource::getPages();
        $routeKey = array_key_exists('view', $pages) ? 'view' : (array_key_exists('edit', $pages) ? 'edit' : null);
        if ($routeKey === null) {
            return null;
        }

        try {
            return $resource::getUrl($routeKey, ['record' => $entity]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Render a message body, replacing every @TYPE-YYYY-NNNNN token whose
     * referenced entity the current user is allowed to view with a chip,
     * and stripping the prefix on the others.
     */
    public function renderBody(string $body, Collection $references): string
    {
        $escaped = e($body);

        $byCode = $references->keyBy('reference_code');

        return preg_replace_callback(
            ReferenceResolver::TOKEN_REGEX,
            function (array $match) use ($byCode) {
                $code = "{$match[1]}-{$match[2]}-{$match[3]}";
                $ref = $byCode->get($code);

                if (! $ref || ! $ref->referenceable) {
                    return e($code);
                }

                if (! Gate::allows('view', $ref->referenceable)) {
                    return e($code);
                }

                $label = e($code);
                $type = e($ref->document_type);

                return sprintf(
                    '<span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 ring-1 ring-inset ring-primary-600/20">%s %s</span>',
                    $type,
                    $label
                );
            },
            $escaped
        ) ?? $escaped;
    }

    public function render(): View
    {
        return view('livewire.messaging.messaging-center');
    }
}
