<?php

namespace Tests\Unit\ProformaInvoices;

use App\Domain\ProformaInvoices\Services\ProformaInvoiceItemCurrencyResolver;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProformaInvoiceItemCurrencyResolverTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoiceItemCurrencyResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new ProformaInvoiceItemCurrencyResolver();
    }

    private function seedCurrencies(): array
    {
        $usd = Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'name_plural' => 'US Dollars',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);

        $cny = Currency::create([
            'code' => 'CNY',
            'name' => 'Chinese Yuan',
            'name_plural' => 'Chinese Yuan',
            'symbol' => '¥',
            'decimal_places' => 2,
            'is_base' => false,
            'is_active' => true,
        ]);

        return [$usd, $cny];
    }

    public function test_same_currency_returns_rate_one(): void
    {
        $result = $this->resolver->resolve('USD', 'USD');

        $this->assertSame('USD', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }

    public function test_resolves_cross_currency_via_base(): void
    {
        [$usd, $cny] = $this->seedCurrencies();

        // base (USD) -> CNY rate = 7.0 means 1 USD = 7 CNY
        // Use yesterday's date so the `date <= today` window in getLatestRate
        // matches cleanly on SQLite (where DATE columns are stored as text).
        ExchangeRate::create([
            'base_currency_id' => $usd->id,
            'target_currency_id' => $cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);

        // Source = CNY, Target = USD → ExchangeRate::convert handles this via inverse_rate
        $result = $this->resolver->resolve('CNY', 'USD');

        $this->assertSame('CNY', $result['currency']);
        $this->assertEqualsWithDelta(1 / 7.0, $result['rate'], 0.0001);
    }

    public function test_unknown_currency_falls_back_to_rate_one(): void
    {
        $result = $this->resolver->resolve('XYZ', 'USD');

        $this->assertSame('XYZ', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }

    public function test_no_rate_available_falls_back_to_rate_one(): void
    {
        $this->seedCurrencies();
        // No ExchangeRate row inserted

        $result = $this->resolver->resolve('CNY', 'USD');

        $this->assertSame('CNY', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }

    public function test_null_source_currency_treated_as_target(): void
    {
        $result = $this->resolver->resolve(null, 'USD');

        $this->assertSame('USD', $result['currency']);
        $this->assertSame(1.0, $result['rate']);
    }
}
