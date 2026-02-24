<?php

namespace App\Domain\Logistics\Models;

use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentItem extends Model
{
    protected $fillable = [
        'shipment_id',
        'proforma_invoice_item_id',
        'purchase_order_item_id',
        'quantity',
        'unit',
        'unit_weight',
        'total_weight',
        'total_volume',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_weight' => 'decimal:3',
            'total_weight' => 'decimal:3',
            'total_volume' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function packingListItems(): HasMany
    {
        return $this->hasMany(PackingListItem::class);
    }

    // --- Accessors ---

    public function getProductNameAttribute(): string
    {
        return $this->proformaInvoiceItem?->product?->name
            ?? $this->proformaInvoiceItem?->description
            ?? '—';
    }

    public function getLineTotalAttribute(): int
    {
        $piItem = $this->proformaInvoiceItem;
        return $piItem ? $piItem->unit_price * $this->quantity : 0;
    }

    public function getUnitPriceAttribute(): int
    {
        return $this->proformaInvoiceItem?->unit_price ?? 0;
    }
}
