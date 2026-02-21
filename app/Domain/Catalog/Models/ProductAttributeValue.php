<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'category_attribute_id',
        'value',
    ];

    // --- Relationships ---

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function categoryAttribute(): BelongsTo
    {
        return $this->belongsTo(CategoryAttribute::class);
    }

    // --- Accessors ---

    public function getFormattedAttribute(): string
    {
        $attr = $this->categoryAttribute;
        $display = "{$attr->name}: {$this->value}";

        if ($attr->unit) {
            $display .= " {$attr->unit}";
        }

        return $display;
    }
}
