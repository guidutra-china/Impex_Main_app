<?php

namespace App\Domain\Messaging\Models;

use App\Domain\Infrastructure\Traits\HasDocuments;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasDocuments, HasFactory, SoftDeletes;

    /** Document type marker used for attachments uploaded inside a chat message. */
    public const ATTACHMENT_TYPE = 'message_attachment';

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'detected_locale',
        'reply_to_id',
        'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(MessageReference::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(MessageTranslation::class);
    }
}
