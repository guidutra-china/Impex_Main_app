<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\AttributeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryAttribute extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'default_value',
        'unit',
        'type',
        'options',
        'is_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'options' => 'array',
            'is_required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function productValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    // --- Accessors ---

    public function getFormattedAttribute(): string
    {
        $display = $this->name;

        if ($this->default_value) {
            $display .= ": {$this->default_value}";
        }

        if ($this->unit) {
            $display .= " {$this->unit}";
        }

        return $display;
    }
}
