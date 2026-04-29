<?php

namespace App\Domain\Quotations\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
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
        'supplier_quotation_item_id',
        'quantity',
        'selected_supplier_id',
        'unit_cost',
        'cost_currency_code',
        'cost_exchange_rate',
        'cost_exchange_rate_captured_at',
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
            'cost_exchange_rate' => 'decimal:8',
            'cost_exchange_rate_captured_at' => 'date',
            'commission_rate' => 'decimal:2',
            'unit_price' => 'integer',
            'incoterm' => Incoterm::class,
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (QuotationItem $item) {
            if ($item->isDirty('cost_exchange_rate') && $item->cost_exchange_rate !== null) {
                if (! $item->isDirty('cost_exchange_rate_captured_at')) {
                    $item->cost_exchange_rate_captured_at = now()->toDateString();
                }
            }
        });
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

    public function supplierQuotationItem(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotationItem::class);
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

    public function getConvertedUnitCostAttribute(): int
    {
        $quoteCurrency = $this->quotation?->currency_code;
        if ($this->cost_currency_code === null
            || $quoteCurrency === null
            || $this->cost_currency_code === $quoteCurrency) {
            return $this->unit_cost;
        }

        $rate = $this->cost_exchange_rate !== null ? (float) $this->cost_exchange_rate : 1.0;

        return (int) round($this->unit_cost * $rate);
    }

    public function getConvertedCostTotalAttribute(): int
    {
        return $this->converted_unit_cost * $this->quantity;
    }

    public function getMarginAttribute(): float
    {
        $cost = $this->converted_unit_cost;
        if ($cost <= 0) {
            return 0;
        }

        return round((($this->unit_price - $cost) / $cost) * 100, 2);
    }

    public function getSourceLabelAttribute(): ?string
    {
        if (! $this->supplier_quotation_item_id) {
            return null;
        }

        $sqItem = $this->supplierQuotationItem()->with('supplierQuotation')->first();
        if (! $sqItem || ! $sqItem->supplierQuotation) {
            return null;
        }

        return $sqItem->supplierQuotation->reference;
    }
}
