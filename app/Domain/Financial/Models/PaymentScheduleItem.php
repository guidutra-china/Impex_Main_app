<?php

namespace App\Domain\Financial\Models;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTermStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentScheduleItem extends Model
{
    protected $fillable = [
        'payable_type',
        'payable_id',
        'payment_term_stage_id',
        'label',
        'percentage',
        'amount',
        'currency_code',
        'due_condition',
        'due_date',
        'status',
        'is_blocking',
        'sort_order',
        'notes',
        'waived_by',
        'waived_at',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'integer',
            'amount' => 'integer',
            'due_condition' => CalculationBase::class,
            'due_date' => 'date',
            'status' => PaymentScheduleStatus::class,
            'is_blocking' => 'boolean',
            'sort_order' => 'integer',
            'waived_at' => 'datetime',
        ];
    }

    // --- Relationships ---

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function paymentTermStage(): BelongsTo
    {
        return $this->belongsTo(PaymentTermStage::class);
    }

    public function waivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // --- Accessors ---

    public function getPaidAmountAttribute(): int
    {
        return $this->payments()
            ->where('status', PaymentStatus::APPROVED)
            ->sum('amount_in_document_currency');
    }

    public function getRemainingAmountAttribute(): int
    {
        return max(0, $this->amount - $this->paid_amount);
    }

    public function getIsPaidInFullAttribute(): bool
    {
        return $this->remaining_amount <= 0;
    }

    public function isResolved(): bool
    {
        return $this->status->isResolved();
    }

    // --- Blocking Logic ---

    public static function blockingConditionsForTransition(Model $payable, string $targetStatus): array
    {
        return static::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->where('is_blocking', true)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->get()
            ->filter(fn (self $item) => $item->blocksTransitionTo($targetStatus))
            ->values()
            ->all();
    }

    public function blocksTransitionTo(string $targetStatus): bool
    {
        if (! $this->is_blocking || $this->isResolved()) {
            return false;
        }

        return match ($this->due_condition) {
            CalculationBase::BEFORE_PRODUCTION => $targetStatus === 'in_production',
            CalculationBase::BEFORE_SHIPMENT => $targetStatus === 'shipped',
            CalculationBase::ORDER_DATE,
            CalculationBase::PO_DATE => $targetStatus === 'confirmed',
            default => false,
        };
    }

    /**
     * Conditions that must be resolved before PO generation.
     * Only upfront/deposit-type conditions block PO creation.
     */
    public function blocksPurchaseOrderGeneration(): bool
    {
        if (! $this->is_blocking || $this->isResolved()) {
            return false;
        }

        return in_array($this->due_condition, [
            CalculationBase::BEFORE_PRODUCTION,
            CalculationBase::ORDER_DATE,
            CalculationBase::PO_DATE,
        ]);
    }

    public static function blockingPurchaseOrderGeneration(Model $payable): array
    {
        return static::where('payable_type', get_class($payable))
            ->where('payable_id', $payable->getKey())
            ->where('is_blocking', true)
            ->whereNotIn('status', [
                PaymentScheduleStatus::PAID->value,
                PaymentScheduleStatus::WAIVED->value,
            ])
            ->get()
            ->filter(fn (self $item) => $item->blocksPurchaseOrderGeneration())
            ->values()
            ->all();
    }
}
