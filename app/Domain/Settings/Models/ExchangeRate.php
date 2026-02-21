<?php

namespace App\Domain\Settings\Models;

use App\Domain\Settings\Enums\ExchangeRateSource;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExchangeRate extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'base_currency_id',
        'target_currency_id',
        'rate',
        'inverse_rate',
        'date',
        'source',
        'source_name',
        'status',
        'approved_by',
        'approved_at',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:8',
            'inverse_rate' => 'decimal:8',
            'date' => 'date',
            'source' => ExchangeRateSource::class,
            'status' => ExchangeRateStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function targetCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'target_currency_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function getLatestRate(int $baseCurrencyId, int $targetCurrencyId, ?string $date = null): ?self
    {
        $date = $date ?? today()->toDateString();

        return static::where('base_currency_id', $baseCurrencyId)
            ->where('target_currency_id', $targetCurrencyId)
            ->where('status', ExchangeRateStatus::APPROVED)
            ->where('date', '<=', $date)
            ->orderBy('date', 'desc')
            ->first();
    }

    public static function convert(int $fromCurrencyId, int $toCurrencyId, float $amount, ?string $date = null): ?float
    {
        if ($fromCurrencyId === $toCurrencyId) {
            return $amount;
        }

        $baseCurrency = Currency::base();

        if (!$baseCurrency) {
            return null;
        }

        if ($fromCurrencyId === $baseCurrency->id) {
            $rate = self::getLatestRate($baseCurrency->id, $toCurrencyId, $date);
            return $rate ? $amount * (float) $rate->rate : null;
        }

        if ($toCurrencyId === $baseCurrency->id) {
            $rate = self::getLatestRate($baseCurrency->id, $fromCurrencyId, $date);
            return $rate ? $amount * (float) $rate->inverse_rate : null;
        }

        $rateBaseToFrom = self::getLatestRate($baseCurrency->id, $fromCurrencyId, $date);
        $rateBaseToTo = self::getLatestRate($baseCurrency->id, $toCurrencyId, $date);

        if (!$rateBaseToFrom || !$rateBaseToTo) {
            return null;
        }

        return $amount * (1 / (float) $rateBaseToFrom->rate) * (float) $rateBaseToTo->rate;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', ExchangeRateStatus::APPROVED);
    }

    public function scopeForDate($query, string $date)
    {
        return $query->where('date', '<=', $date)->orderBy('date', 'desc');
    }
}
