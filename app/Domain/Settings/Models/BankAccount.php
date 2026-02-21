<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\BankAccountStatus;
use App\Domain\Settings\Enums\BankAccountType;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $fillable = [
        'account_name',
        'bank_name',
        'account_number',
        'routing_number',
        'swift_code',
        'iban',
        'currency_id',
        'account_type',
        'status',
        'current_balance',
        'available_balance',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'account_type' => BankAccountType::class,
            'status' => BankAccountStatus::class,
            'current_balance' => 'integer',
            'available_balance' => 'integer',
        ];
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function getCurrentBalanceMoneyAttribute(): ?Money
    {
        if ($this->current_balance === null || !$this->currency) {
            return null;
        }

        return Money::ofMinor($this->current_balance, $this->currency->code);
    }

    public function getAvailableBalanceMoneyAttribute(): ?Money
    {
        if ($this->available_balance === null || !$this->currency) {
            return null;
        }

        return Money::ofMinor($this->available_balance, $this->currency->code);
    }

    public function getFormattedCurrentBalanceAttribute(): string
    {
        $money = $this->current_balance_money;

        if (!$money) {
            return '—';
        }

        return $money->formatTo('en_US');
    }

    public function getFormattedAvailableBalanceAttribute(): string
    {
        $money = $this->available_balance_money;

        if (!$money) {
            return '—';
        }

        return $money->formatTo('en_US');
    }

    public function scopeActive($query)
    {
        return $query->where('status', BankAccountStatus::ACTIVE);
    }
}
