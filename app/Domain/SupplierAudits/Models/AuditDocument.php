<?php

namespace App\Domain\SupplierAudits\Models;

use App\Domain\SupplierAudits\Enums\AuditDocumentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AuditDocument extends Model
{
    protected $fillable = [
        'supplier_audit_id',
        'audit_category_id',
        'type',
        'title',
        'disk',
        'path',
        'original_name',
        'size',
        'mime_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => AuditDocumentType::class,
            'size' => 'integer',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(SupplierAudit::class, 'supplier_audit_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AuditCategory::class, 'audit_category_id');
    }

    public function getUrl(): ?string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getFullPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function getFormattedSize(): string
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

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }
}
