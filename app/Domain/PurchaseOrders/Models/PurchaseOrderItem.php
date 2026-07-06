<?php

namespace App\Domain\PurchaseOrders\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'proforma_invoice_item_id',
        'supplier_quotation_item_id',
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

    protected static function booted(): void
    {
        // Bloqueia apagar uma linha de PO que já tem embarque. O FK
        // shipment_items.purchase_order_item_id é "on delete set null", então
        // apagar a linha zeraria silenciosamente o vínculo do embarque com a PO
        // (a baixa contra a PI continua, mas o acompanhamento da PO fica furado).
        // Cobre exclusão manual (single/bulk) e qualquer chamada a delete();
        // a regeração da PO e o hook de exclusão de item da PI já excluem
        // apenas linhas sem embarque, então esta guarda nunca dispara neles.
        static::deleting(function (PurchaseOrderItem $item) {
            if ($item->shipmentItems()->exists()) {
                return false;
            }
        });
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

    public function supplierQuotationItem(): BelongsTo
    {
        return $this->belongsTo(SupplierQuotationItem::class);
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class);
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

    public function getQuantityShippedAttribute(): int
    {
        return $this->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->whereIn('status', [
                ShipmentStatus::IN_TRANSIT->value,
                ShipmentStatus::ARRIVED->value,
            ]))
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
