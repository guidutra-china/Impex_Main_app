<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLog extends Model
{
    protected $fillable = [
        'user_id',
        'company_id',
        'import_type',
        'file_name',
        'row_count',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'errors',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'errors' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\CRM\Models\Company::class);
    }

    public function markCompleted(array $stats): void
    {
        $this->update([
            'created_count' => $stats['created'] ?? 0,
            'updated_count' => $stats['updated'] ?? 0,
            'skipped_count' => $stats['skipped'] ?? 0,
            'error_count' => count($stats['errors'] ?? []),
            'errors' => $stats['errors'] ?? [],
            'completed_at' => now(),
        ]);
    }

    public static function start(
        string $importType,
        string $fileName,
        int $rowCount,
        ?int $companyId = null,
        array $metadata = [],
    ): self {
        return self::create([
            'user_id' => auth()->id(),
            'company_id' => $companyId,
            'import_type' => $importType,
            'file_name' => $fileName,
            'row_count' => $rowCount,
            'metadata' => $metadata,
            'started_at' => now(),
        ]);
    }
}
