<?php

namespace App\Domain\SupplierQuotations\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Models\InquiryItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierQuotationItem extends Model
{
    protected $fillable = [
        'supplier_quotation_id',
        'inquiry_item_id',
        'product_id',
        'description',
        'quantity',
        'unit',
        'unit_cost',
        'total_cost',
        'moq',
        'lead_time_days',
        'specifications',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'total_cost' => 'integer',
            'moq' => 'integer',
            'lead_time_days' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::saving(function (SupplierQuotationItem $item) {
            $item->total_cost = $item->quantity * $item->unit_cost;
        });
    }

    // --- Relationships ---

    public function supplierQuotation(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotation::class);
    }

    public function inquiryItem(): BelongsTo
    {
        return $this->belongsTo(InquiryItem::class);
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

        return $this->description ?? 'Unnamed item';
    }
}
