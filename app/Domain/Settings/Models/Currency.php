<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'name_plural',
        'symbol',
        'decimal_places',
        'is_base',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_base' => 'boolean',
            'is_active' => 'boolean',
            'decimal_places' => 'integer',
        ];
    }

    public function exchangeRatesAsBase(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'base_currency_id');
    }

    public function exchangeRatesAsTarget(): HasMany
    {
        return $this->hasMany(ExchangeRate::class, 'target_currency_id');
    }

    /**
     * Memoized per request via once() — currencies don't change mid-request
     * and dashboard widgets call this dozens of times per render. The test
     * framework flushes the once() cache between tests automatically.
     */
    public static function base(): ?self
    {
        return once(fn (): ?self => static::where('is_base', true)->first());
    }

    /** Memoized per request per code via once(). */
    public static function findByCode(string $code): ?self
    {
        return once(fn (): ?self => static::where('code', $code)->first());
    }
}
