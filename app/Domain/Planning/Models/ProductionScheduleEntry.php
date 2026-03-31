<?php

namespace App\Domain\Planning\Models;

use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionScheduleEntry extends Model
{
    use HasFactory;
    protected $fillable = [
        'production_schedule_id',
        'proforma_invoice_item_id',
        'purchase_order_item_id',
        'production_date',
        'quantity',
        'actual_quantity',
    ];

    protected function casts(): array
    {
        return [
            'production_date' => 'date',
            'quantity' => 'integer',
            'actual_quantity' => 'integer',
        ];
    }

    // --- Relationships ---

    public function productionSchedule(): BelongsTo
    {
        return $this->belongsTo(ProductionSchedule::class);
    }

    public function proformaInvoiceItem(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoiceItem::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }
}
