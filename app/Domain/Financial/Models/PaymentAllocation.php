<?php

namespace App\Domain\Financial\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentAllocation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'payment_schedule_item_id',
        'credit_schedule_item_id',
        'allocated_amount',
        'exchange_rate',
        'allocated_amount_in_document_currency',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'integer',
            'exchange_rate' => 'decimal:8',
            'allocated_amount_in_document_currency' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(PaymentScheduleItem::class, 'payment_schedule_item_id');
    }

    public function creditItem(): BelongsTo
    {
        return $this->belongsTo(PaymentScheduleItem::class, 'credit_schedule_item_id');
    }

    public function isCreditApplication(): bool
    {
        return $this->credit_schedule_item_id !== null;
    }
}
