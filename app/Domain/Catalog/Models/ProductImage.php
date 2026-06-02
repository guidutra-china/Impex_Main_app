<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
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
        // Delete the file on disk only if no other gallery row and no product
        // avatar still references the same path (deduplication-safe cleanup).
        static::deleting(function (ProductImage $image) {
            if (! $image->path) {
                return;
            }

            $referenced = static::where('path', $image->path)
                ->whereKeyNot($image->getKey())
                ->exists()
                || Product::withTrashed()->where('avatar', $image->path)->exists();

            if (! $referenced) {
                Storage::disk($image->disk ?? 'public')->delete($image->path);
            }
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->path) {
            return null;
        }

        return Storage::disk($this->disk ?? 'public')->url($this->path);
    }
}
