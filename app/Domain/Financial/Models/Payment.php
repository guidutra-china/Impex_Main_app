<?php

namespace App\Domain\Financial\Models;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Settings\Models\BankAccount;
use App\Domain\Settings\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'direction',
        'company_id',
        'amount',
        'currency_code',
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
            'payment_date' => 'date',
            'status' => PaymentStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment) {
            if (empty($payment->created_by)) {
                $payment->created_by = auth()->id();
            }
        });
    }

    // --- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
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

    // --- Computed ---

    public function getAllocatedTotalAttribute(): int
    {
        return (int) $this->allocations()->sum('allocated_amount');
    }

    public function getUnallocatedAmountAttribute(): int
    {
        return max(0, $this->amount - $this->allocated_total);
    }

    public function isFullyAllocated(): bool
    {
        return $this->unallocated_amount <= 0;
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
