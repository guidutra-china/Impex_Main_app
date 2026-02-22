<?php

namespace App\Domain\Infrastructure\Models;

use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'type',
        'name',
        'disk',
        'path',
        'version',
        'source',
        'checksum',
        'mime_type',
        'size',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'source' => DocumentSourceType::class,
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function getUrl(): ?string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getFullPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }
}
