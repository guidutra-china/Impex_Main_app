<?php

namespace App\Domain\ProformaInvoices\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Planning\Models\ShipmentPlanItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProformaInvoiceItem extends Model
{
    use HasFactory;
    protected $fillable = [
        'proforma_invoice_id',
        'product_id',
        'quotation_item_id',
        'supplier_company_id',
        'description',
        'specifications',
        'quantity',
        'unit',
        'unit_price',
        'unit_cost',
        'cost_currency_code',
        'cost_exchange_rate',
        'unit_cost_in_document_currency',
        'incoterm',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'unit_cost' => 'integer',
            'cost_exchange_rate' => 'decimal:8',
            'unit_cost_in_document_currency' => 'integer',
            'incoterm' => Incoterm::class,
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ProformaInvoiceItem $item) {
            // Skip when an unrelated field is being updated on an existing row.
            if ($item->exists && ! $item->isDirty(['unit_cost', 'cost_exchange_rate'])) {
                return;
            }

            // Treat null/non-positive rate as 1 (matches the column default and
            // avoids silently zeroing the cached doc-currency cost).
            $rate = $item->cost_exchange_rate;
            if ($rate === null || (float) $rate <= 0) {
                $rate = 1.0;
                $item->cost_exchange_rate = 1.0;
            }

            $item->unit_cost_in_document_currency = (int) round(
                (int) $item->unit_cost * (float) $rate
            );
        });
    }

    // --- Relationships ---

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function supplierCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'supplier_company_id');
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
    }

    public function shipmentPlanItems(): HasMany
    {
        return $this->hasMany(ShipmentPlanItem::class);
    }

    public function purchaseOrderItem(): HasOne
    {
        return $this->hasOne(PurchaseOrderItem::class);
    }

    public function getQuantityPlannedAttribute(): int
    {
        return $this->shipmentPlanItems()
            ->whereHas('shipmentPlan', fn ($q) => $q->whereNotIn('status', ['cancelled']))
            ->sum('quantity');
    }

    public function getQuantityAvailableForPlanningAttribute(): int
    {
        return max(0, $this->quantity - $this->quantity_shipped - $this->quantity_planned);
    }

    // --- Accessors ---

    public function getLineTotalAttribute(): int
    {
        return $this->unit_price * $this->quantity;
    }

    public function getCostTotalAttribute(): int
    {
        return $this->unit_cost_in_document_currency * $this->quantity;
    }

    public function getMarginAttribute(): float
    {
        $cost = $this->unit_cost_in_document_currency;
        if ($cost <= 0) {
            return 0;
        }

        return round((($this->unit_price - $cost) / $cost) * 100, 2);
    }

    public function getProductNameAttribute(): string
    {
        return $this->product?->name ?? $this->description ?? '—';
    }

    public function getQuantityShippedAttribute(): int
    {
        return $this->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->where('status', '!=', ShipmentStatus::CANCELLED))
            ->sum('quantity');
    }

    public function getQuantityRemainingAttribute(): int
    {
        return max(0, $this->quantity - $this->quantity_shipped);
    }

    public function getIsFullyShippedAttribute(): bool
    {
        return $this->quantity_remaining <= 0;
    }

    public function getShipmentReferencesAttribute(): string
    {
        return $this->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->where('status', '!=', ShipmentStatus::CANCELLED))
            ->with('shipment')
            ->get()
            ->pluck('shipment.reference')
            ->unique()
            ->implode(', ');
    }
}
