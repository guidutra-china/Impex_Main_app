<?php

namespace App\Domain\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTranslation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'message_id',
        'locale',
        'body',
        'provider',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
