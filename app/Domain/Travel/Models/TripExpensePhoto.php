<?php

namespace App\Domain\Travel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TripExpensePhoto extends Model
{
    protected $fillable = [
        'trip_expense_id',
        'disk',
        'path',
        'sort_order',
        'is_primary',
        'original_name',
        'size',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'sort_order' => 'integer',
            'size' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Delete the file on disk only if no other row still references the same
        // path (deduplication-safe cleanup).
        static::deleting(function (TripExpensePhoto $photo) {
            if (! $photo->path) {
                return;
            }

            $referenced = static::where('path', $photo->path)
                ->whereKeyNot($photo->getKey())
                ->exists();

            if (! $referenced) {
                Storage::disk($photo->disk ?? 'public')->delete($photo->path);
            }
        });
    }

    public function tripExpense(): BelongsTo
    {
        return $this->belongsTo(TripExpense::class);
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return Storage::disk($this->disk ?? 'public')->url($this->path);
    }
}
