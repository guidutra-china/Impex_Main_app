<?php

namespace App\Domain\CRM\Models;

use App\Domain\CRM\Enums\DocumentCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CompanyDocument extends Model
{
    protected $fillable = [
        'company_id',
        'category',
        'title',
        'disk',
        'path',
        'original_name',
        'size',
        'expiry_date',
        'notes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'expiry_date' => 'date',
            'size' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrl(): ?string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expiry_date
            && ! $this->isExpired()
            && $this->expiry_date->diffInDays(now()) <= $days;
    }
}
