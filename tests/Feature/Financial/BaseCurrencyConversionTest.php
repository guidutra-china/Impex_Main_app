<?php

namespace Tests\Feature\Financial;

use App\Domain\Financial\Support\BaseCurrency;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BaseCurrencyConversionTest extends TestCase
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

        // USD (base) → CNY at 7.0, so CNY → USD uses the inverse rate 1/7.
        ExchangeRate::create([
            'base_currency_id' => $this->usd->id,
            'target_currency_id' => $this->cny->id,
            'rate' => 7.0,
            'inverse_rate' => 1 / 7.0,
            'date' => now()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_converts_foreign_totals_through_real_exchange_rate(): void
    {
        // CNY 7,000.00 (minor units) at inverse rate 1/7 → USD 1,000.00.
        $result = BaseCurrency::convert(collect(['CNY' => 700_000]));

        $this->assertSame((int) round(700_000 * (1 / 7.0)), $result['total']);
        $this->assertSame(100_000, $result['total']);
        $this->assertSame([], $result['unconverted']);
    }

    public function test_base_currency_amounts_pass_through_and_mix_with_converted(): void
    {
        $result = BaseCurrency::convert(collect(['USD' => 250_000, 'CNY' => 700_000]));

        $this->assertSame(350_000, $result['total']);
        $this->assertSame([], $result['unconverted']);
    }

    public function test_unknown_currency_is_reported_as_unconverted(): void
    {
        $result = BaseCurrency::convert(collect(['USD' => 100_000, 'BRL' => 500_000]));

        $this->assertSame(100_000, $result['total']);
        $this->assertSame(['BRL'], $result['unconverted']);
    }
}
