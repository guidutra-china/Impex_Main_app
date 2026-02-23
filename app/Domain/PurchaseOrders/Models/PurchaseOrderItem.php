<?php

namespace App\Domain\PurchaseOrders\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\Incoterm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'proforma_invoice_item_id',
        'description',
        'specifications',
        'quantity',
        'unit',
        'unit_cost',
        'incoterm',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'integer',
            'incoterm' => Incoterm::class,
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    // --- Accessors ---

    public function getLineTotalAttribute(): int
    {
        return $this->unit_cost * $this->quantity;
    }

    public function getProductNameAttribute(): string
    {
        return $this->product?->name ?? $this->description ?? '—';
    }
}
