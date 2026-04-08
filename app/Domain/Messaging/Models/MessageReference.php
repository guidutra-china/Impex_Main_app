<?php

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MessageReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'referenceable_id',
        'referenceable_type',
        'reference_code',
        'document_type',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function referenceable(): MorphTo
    {
        return $this->morphTo();
    }
}
