<?php

use App\Domain\Messaging\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/**
 * Private conversation channel.
 *
 * Authorization is delegated to ConversationPolicy@view, so the same rule
 * that gates HTTP access also gates websocket subscriptions. If the user
 * is not a participant (or the conversation does not exist), they cannot
 * subscribe and will not receive MessageSent broadcasts.
 */
Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId) {
    $conversation = Conversation::find($conversationId);

    return $conversation !== null && $user->can('view', $conversation);
});
