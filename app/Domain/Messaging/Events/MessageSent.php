<?php

namespace App\Domain\Messaging\Events;

use App\Domain\Messaging\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * ShouldBroadcastNow (not ShouldBroadcast) — broadcasts synchronously during
 * the request. The MessagingService wraps the dispatch in a try/catch so
 * Reverb being offline never blocks message persistence; the user simply
 * loses real-time push for that message but the DB notification still fires.
 */
class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Message $message)
    {
    }

    /**
     * Broadcast on the conversation's private channel. Listeners refresh
     * via Livewire so the per-user policy filter is re-applied server-side
     * and no sensitive payload travels through the websocket.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("conversation.{$this->message->conversation_id}");
    }

    public function broadcastAs(): string
    {
        return 'MessageSent';
    }

    /**
     * Lean payload — the actual message body and chips are loaded by Livewire
     * after the event so policies and rendering stay server-side.
     */
    public function broadcastWith(): array
    {
        return [
            'message_id'      => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id'       => $this->message->sender_id,
            'created_at'      => $this->message->created_at?->toIso8601String(),
        ];
    }
}
