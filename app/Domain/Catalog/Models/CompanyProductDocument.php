<?php

namespace App\Domain\Catalog\Models;

use App\Domain\CRM\Enums\DocumentCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CompanyProductDocument extends Model
{
    protected $fillable = [
        'company_product_id',
        'category',
        'title',
        'disk',
        'path',
        'original_name',
        'size',
        'notes',
        'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'size' => 'integer',
        ];
    }

    public function companyProduct(): BelongsTo
    {
        return $this->belongsTo(CompanyProduct::class);
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
}
