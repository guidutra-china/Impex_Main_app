<?php

namespace App\Domain\Planning\Models;

use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionScheduleEntry extends Model
{
    protected $fillable = [
        'production_schedule_id',
        'proforma_invoice_item_id',
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
}
