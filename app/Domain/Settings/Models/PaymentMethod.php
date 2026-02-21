<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\FeeType;
use App\Domain\Settings\Enums\PaymentMethodType;
use App\Domain\Settings\Enums\ProcessingTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    protected $fillable = [
        'name',
        'type',
        'bank_account_id',
        'fee_type',
        'fixed_fee_amount',
        'fixed_fee_currency_id',
        'percentage_fee',
        'processing_time',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'fee_type' => FeeType::class,
            'processing_time' => ProcessingTime::class,
            'is_active' => 'boolean',
            'percentage_fee' => 'decimal:2',
            'fixed_fee_amount' => 'integer',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function fixedFeeCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'fixed_fee_currency_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
