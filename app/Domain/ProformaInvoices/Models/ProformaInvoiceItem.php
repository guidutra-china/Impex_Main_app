<?php

namespace App\Domain\ProformaInvoices\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProformaInvoiceItem extends Model
{
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
            'incoterm' => Incoterm::class,
            'sort_order' => 'integer',
        ];
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
