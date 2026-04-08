<?php

namespace App\Domain\Messaging\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ConversationParticipant extends Pivot
{
    protected $table = 'conversation_participants';

    public $incrementing = true;

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'last_read_at',
        'muted_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'last_read_at' => 'datetime',
            'muted_at'     => 'datetime',
            'left_at'      => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
