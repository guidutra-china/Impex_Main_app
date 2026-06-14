<?php

namespace App\Domain\Financial\Models;

use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebitNoteLineItem extends Model
{
    protected $fillable = [
        'debit_note_id',
        'additional_cost_id',
        'proforma_invoice_id',
        'purchase_order_id',
        'shipment_id',
        'description',
        'amount',
        'currency_code',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    // --- Relationships ---

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(DebitNote::class);
    }

    public function additionalCost(): BelongsTo
    {
        return $this->belongsTo(AdditionalCost::class);
    }

    public function proformaInvoice(): BelongsTo
    {
        return $this->belongsTo(ProformaInvoice::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
