<?php

namespace Tests\Unit\Domain\Settings;

use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Exceptions\CurrencyExchangeRateUnavailable;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrencyExchangeResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_same_currency_returns_rate_1(): void
    {
        $resolver = new CurrencyExchangeResolver;
        $result = $resolver->resolve('USD', 'USD');
        $this->assertSame(['currency' => 'USD', 'rate' => 1.0, 'rate_date' => null], $result);
    }

    public function test_null_source_falls_back_to_target(): void
    {
        $resolver = new CurrencyExchangeResolver;
        $result = $resolver->resolve(null, 'USD');
        $this->assertSame(['currency' => 'USD', 'rate' => 1.0, 'rate_date' => null], $result);
    }

    public function test_cny_to_usd_resolves_inverse_rate(): void
    {
        $resolver = new CurrencyExchangeResolver;
        $result = $resolver->resolve('CNY', 'USD');
        $this->assertSame('CNY', $result['currency']);
        $this->assertEqualsWithDelta(1 / 7.0, $result['rate'], 0.0001);
        $this->assertSame(today()->subDay()->toDateString(), $result['rate_date']);
    }

    public function test_unknown_currency_lenient_returns_rate_1(): void
    {
        $resolver = new CurrencyExchangeResolver;
        $result = $resolver->resolve('XYZ', 'USD');
        $this->assertSame(['currency' => 'XYZ', 'rate' => 1.0, 'rate_date' => null], $result);
    }

    public function test_missing_rate_strict_throws(): void
    {
        Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'name_plural' => 'Euros',
            'symbol' => '€', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);

        $this->expectException(CurrencyExchangeRateUnavailable::class);

        $resolver = new CurrencyExchangeResolver;
        $resolver->resolve('EUR', 'USD', null, strict: true);
    }
}
