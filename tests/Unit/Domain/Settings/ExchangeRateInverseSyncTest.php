<?php

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateInverseSyncTest extends TestCase
{
    use RefreshDatabase;

    private Currency $usd;

    private Currency $cny;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $this->cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
    }

    private function buildRate(float $rate = 6.7745): ExchangeRate
    {
        return ExchangeRate::create([
            'base_currency_id' => $this->usd->id,
            'target_currency_id' => $this->cny->id,
            'rate' => $rate,
            'inverse_rate' => round(1 / $rate, 8),
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_updating_only_the_rate_recomputes_the_inverse(): void
    {
        $exchangeRate = $this->buildRate(6.7745);

        $exchangeRate->update(['rate' => 6.5]);

        $exchangeRate->refresh();
        $this->assertEqualsWithDelta(round(1 / 6.5, 8), (float) $exchangeRate->inverse_rate, 0.00000001);
    }

    public function test_explicit_inverse_is_preserved_when_updating_both(): void
    {
        $exchangeRate = $this->buildRate(6.7745);

        $exchangeRate->update(['rate' => 6.5, 'inverse_rate' => 0.16]);

        $exchangeRate->refresh();
        $this->assertEqualsWithDelta(0.16, (float) $exchangeRate->inverse_rate, 0.00000001);
    }

    public function test_creating_without_inverse_auto_fills_it(): void
    {
        $exchangeRate = ExchangeRate::create([
            'base_currency_id' => $this->usd->id,
            'target_currency_id' => $this->cny->id,
            'rate' => 8.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);

        $exchangeRate->refresh();
        $this->assertEqualsWithDelta(0.125, (float) $exchangeRate->inverse_rate, 0.00000001);
    }

    public function test_resaving_the_stale_inverse_alongside_a_new_rate_recomputes_it(): void
    {
        // Mirrors the edit form: the old inverse is posted back unchanged
        // while the rate is updated, so it must not be treated as custom.
        $exchangeRate = $this->buildRate(6.7745);
        $staleInverse = $exchangeRate->inverse_rate;

        $exchangeRate->update(['rate' => 6.5, 'inverse_rate' => $staleInverse]);

        $exchangeRate->refresh();
        $this->assertEqualsWithDelta(round(1 / 6.5, 8), (float) $exchangeRate->inverse_rate, 0.00000001);
    }
}
