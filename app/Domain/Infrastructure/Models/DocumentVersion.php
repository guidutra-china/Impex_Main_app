<?php

namespace App\Domain\Infrastructure\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class DocumentVersion extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'path',
        'version',
        'checksum',
        'size',
        'created_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getFullPath(): string
    {
        $disk = $this->document->disk ?? 'local';

        return Storage::disk($disk)->path($this->path);
    }
}
