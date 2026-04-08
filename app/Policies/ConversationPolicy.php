<?php

namespace App\Policies;

use App\Domain\Messaging\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function viewAny(User $user): bool
    {
        // Anyone authenticated can see their own inbox.
        return true;
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->hasParticipant($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->created_by === $user->id;
    }

    public function delete(User $user, Conversation $conversation): bool
    {
        return $conversation->created_by === $user->id;
    }
}
