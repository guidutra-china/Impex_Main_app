<?php

namespace App\Domain\Quotations\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\Incoterm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'product_id',
        'quantity',
        'selected_supplier_id',
        'unit_cost',
        'commission_rate',
        'unit_price',
        'incoterm',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'commission_rate' => 'decimal:2',
            'unit_price' => 'integer',
            'incoterm' => Incoterm::class,
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function selectedSupplier(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'selected_supplier_id');
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(QuotationItemSupplier::class);
    }

    // --- Accessors ---

    public function getLineTotalAttribute(): int
    {
        return $this->unit_price * $this->quantity;
    }

    public function getCostTotalAttribute(): int
    {
        return $this->unit_cost * $this->quantity;
    }

    public function getMarginAttribute(): float
    {
        if ($this->unit_cost <= 0) {
            return 0;
        }

        return round((($this->unit_price - $this->unit_cost) / $this->unit_cost) * 100, 2);
    }
}
