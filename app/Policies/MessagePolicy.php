<?php

namespace App\Policies;

use App\Domain\Messaging\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function view(User $user, Message $message): bool
    {
        return $message->conversation->hasParticipant($user);
    }

    public function create(User $user, Message $message): bool
    {
        return $message->conversation->hasParticipant($user);
    }

    /**
     * Authors can edit their own messages within a 15-minute window.
     */
    public function update(User $user, Message $message): bool
    {
        if ($message->sender_id !== $user->id) {
            return false;
        }

        return $message->created_at->gt(now()->subMinutes(15));
    }

    /**
     * Authors can soft-delete their own messages within a 15-minute window.
     */
    public function delete(User $user, Message $message): bool
    {
        return $this->update($user, $message);
    }
}
