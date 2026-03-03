<?php

namespace App\Domain\Planning\Models;

use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentPlanItem extends Model
{
    protected $fillable = [
        'shipment_plan_id',
        'proforma_invoice_item_id',
        'quantity',
        'unit_price',
        'line_total',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'line_total' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function shipmentPlan(): BelongsTo
    {
        return $this->belongsTo(ShipmentPlan::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    // --- Accessors ---

    public function getProductNameAttribute(): string
    {
        return $this->proformaInvoiceItem?->product_name ?? '—';
    }

    public function getProformaInvoiceReferenceAttribute(): string
    {
        return $this->proformaInvoiceItem?->proformaInvoice?->reference ?? '—';
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (ShipmentPlanItem $item) {
            if (empty($item->unit_price) && $item->proformaInvoiceItem) {
                $item->unit_price = $item->proformaInvoiceItem->unit_price;
            }

            if (empty($item->line_total)) {
                $item->line_total = $item->unit_price * $item->quantity;
            }
        });

        static::updating(function (ShipmentPlanItem $item) {
            if ($item->isDirty('quantity') || $item->isDirty('unit_price')) {
                $item->line_total = $item->unit_price * $item->quantity;
            }
        });
    }
}
