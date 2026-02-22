<?php

namespace App\Domain\Inquiries\Models;

use App\Domain\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryItem extends Model
{
    protected $fillable = [
        'inquiry_id',
        'product_id',
        'description',
        'quantity',
        'unit',
        'target_price',
        'specifications',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'target_price' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // --- Accessors ---

    public function getDisplayNameAttribute(): string
    {
        if ($this->product) {
            return $this->product->name;
        }

        return $this->description ?? 'Unnamed Item';
    }
}
