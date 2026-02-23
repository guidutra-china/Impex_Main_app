<?php

namespace App\Domain\Financial\Models;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'payment_schedule_item_id',
        'payable_type',
        'payable_id',
        'direction',
        'amount',
        'currency_code',
        'exchange_rate',
        'amount_in_document_currency',
        'payment_method_id',
        'bank_account_id',
        'payment_date',
        'reference',
        'status',
        'approved_by',
        'approved_at',
        'notes',
        'attachment_path',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => PaymentDirection::class,
            'amount' => 'integer',
            'exchange_rate' => 'decimal:8',
            'amount_in_document_currency' => 'integer',
            'payment_date' => 'date',
            'status' => PaymentStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    // --- Boot ---

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->created_by)) {
                $payment->created_by = auth()->id();
            }
        });
    }

    // --- Relationships ---

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(PaymentScheduleItem::class, 'payment_schedule_item_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // --- Scopes ---

    public function scopeApproved($query)
    {
        return $query->where('status', PaymentStatus::APPROVED);
    }

    public function scopeInbound($query)
    {
        return $query->where('direction', PaymentDirection::INBOUND);
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', PaymentDirection::OUTBOUND);
    }
}
