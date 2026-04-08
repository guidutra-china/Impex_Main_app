<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Messaging\Events\MessageSent;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\ConversationParticipant;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageReference;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Storage;

class MessagingService
{
    public function __construct(
        private readonly ReferenceResolver $resolver,
        private readonly MessageTranslator $translator,
    ) {
    }

    /**
     * Create a new conversation between users. The creator becomes owner;
     * other users join as members. Duplicate user ids are de-duped.
     *
     * @param  array<int, int>  $participantUserIds
     */
    public function createConversation(
        User $creator,
        array $participantUserIds,
        ?string $subject = null,
        string $type = 'direct',
        ?Model $subjectEntity = null,
    ): Conversation {
        return DB::transaction(function () use ($creator, $participantUserIds, $subject, $type, $subjectEntity) {
            $conversation = Conversation::create([
                'subject'             => $subject,
                'type'                => $type,
                'created_by'          => $creator->id,
                'subject_entity_id'   => $subjectEntity?->getKey(),
                'subject_entity_type' => $subjectEntity ? $subjectEntity::class : null,
            ]);

            $userIds = collect($participantUserIds)
                ->push($creator->id)
                ->unique()
                ->values();

            foreach ($userIds as $userId) {
                ConversationParticipant::create([
                    'conversation_id' => $conversation->id,
                    'user_id'         => $userId,
                    'role'            => $userId === $creator->id ? 'owner' : 'member',
                ]);
            }

            return $conversation->fresh(['participants']);
        });
    }

    /**
     * Send a message in a conversation. Sanitizes the body, resolves entity
     * references, persists MessageReference rows, stores any uploaded files
     * as Documents, updates last_message_at, and dispatches Filament database
     * notifications to other participants.
     *
     * @param  array<int, UploadedFile>  $attachments
     */
    public function sendMessage(
        Conversation $conversation,
        User $sender,
        string $body,
        array $attachments = [],
    ): Message {
        $clean = $this->sanitize($body);

        // Empty body is allowed when at least one attachment is present
        // (people often drop a document with no extra text).
        if ($clean === '' && empty($attachments)) {
            throw new \InvalidArgumentException('Message body cannot be empty.');
        }

        if (! $conversation->hasParticipant($sender)) {
            throw new \DomainException('Sender is not a participant of this conversation.');
        }

        // Detect source language outside the transaction so a slow Claude
        // call never holds a row lock. Best-effort: null on failure is fine.
        $detectedLocale = null;
        try {
            $detectedLocale = $this->translator->detect($clean);
        } catch (\Throwable $e) {
            Log::warning('Language detection failed (non-fatal): ' . $e->getMessage());
        }

        return DB::transaction(function () use ($conversation, $sender, $clean, $detectedLocale, $attachments) {
            /** @var Message $message */
            $message = $conversation->messages()->create([
                'sender_id'       => $sender->id,
                'body'            => $clean,
                'detected_locale' => $detectedLocale,
            ]);

            // Resolve & persist references.
            $resolved = $this->resolver->resolve($clean);
            foreach ($resolved as $entry) {
                MessageReference::create([
                    'message_id'         => $message->id,
                    'referenceable_id'   => $entry['model']->getKey(),
                    'referenceable_type' => $entry['model']::class,
                    'reference_code'     => $entry['code'],
                    'document_type'      => $entry['type'],
                ]);
            }

            // Persist attachments as Document rows. We bypass DocumentService
            // because its createOrVersion logic enforces 1 doc per (model, type),
            // which is wrong for chat — each attachment is a distinct file.
            foreach ($attachments as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    continue;
                }
                $this->attachFile($message, $file);
            }

            $conversation->update(['last_message_at' => $message->created_at]);

            $this->notifyOtherParticipants($conversation, $message, $sender);

            $message->load('references.referenceable');

            // Broadcast after-commit so listeners always see the persisted row.
            // MessageSent uses ShouldBroadcastNow → runs synchronously; we
            // swallow connection errors so a down Reverb never blocks message
            // sending. The DB-side notification still ensures delivery.
            DB::afterCommit(function () use ($message) {
                try {
                    broadcast(new MessageSent($message))->toOthers();
                } catch (\Throwable $e) {
                    Log::warning('MessageSent broadcast failed (non-fatal): ' . $e->getMessage());
                }
            });

            return $message;
        });
    }

    /**
     * Persist one uploaded file as a Document row attached to a Message,
     * bypassing DocumentService's versioning so multiple distinct files can
     * coexist on the same message.
     */
    private function attachFile(Message $message, UploadedFile $file): Document
    {
        $disk = 'local';
        $directory = "messaging/conversations/{$message->conversation_id}/messages/{$message->id}";
        $path = $file->store($directory, $disk);

        $absolutePath = Storage::disk($disk)->path($path);

        return Document::create([
            'documentable_type' => Message::class,
            'documentable_id'   => $message->id,
            'type'              => Message::ATTACHMENT_TYPE,
            'name'              => $file->getClientOriginalName(),
            'disk'              => $disk,
            'path'              => $path,
            'version'           => 1,
            'source'            => DocumentSourceType::UPLOADED,
            'checksum'          => hash_file('sha256', $absolutePath) ?: null,
            'mime_type'         => $file->getClientMimeType() ?: (mime_content_type($absolutePath) ?: null),
            'size'              => filesize($absolutePath) ?: null,
            'created_by'        => $message->sender_id,
        ]);
    }

    /**
     * Mark a conversation as read up to "now" for the given user.
     */
    public function markAsRead(Conversation $conversation, User $user): void
    {
        ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->update(['last_read_at' => now()]);
    }

    /**
     * Light HTML/text sanitization. We strip all tags and trim whitespace —
     * the renderer is responsible for converting @TYPE-YYYY-NNNNN tokens to
     * chips at display time, so the stored body is plain text.
     */
    private function sanitize(string $body): string
    {
        $stripped = strip_tags($body);
        $normalized = preg_replace("/[\r\n]+/", "\n", $stripped) ?? '';

        return trim($normalized);
    }

    private function notifyOtherParticipants(Conversation $conversation, Message $message, User $sender): void
    {
        $recipients = $conversation->users()
            ->where('users.id', '!=', $sender->id)
            ->whereNull('conversation_participants.left_at')
            ->whereNull('conversation_participants.muted_at')
            ->get();

        $title = $conversation->subject
            ? "New message in: {$conversation->subject}"
            : "New message from {$sender->name}";

        // Build the Filament DatabaseNotification once per recipient and dispatch
        // synchronously via Notification::sendNow(). This bypasses Filament's
        // built-in ShouldQueue contract so the notification appears in the bell
        // immediately without requiring a `queue:work` worker.
        foreach ($recipients as $recipient) {
            $databaseNotification = Notification::make()
                ->title($title)
                ->body(\Illuminate\Support\Str::of($message->body)->limit(120))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->info()
                ->toDatabase();

            NotificationFacade::sendNow($recipient, $databaseNotification);
        }
    }
}
