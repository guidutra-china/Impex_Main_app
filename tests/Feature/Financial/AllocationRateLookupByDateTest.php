<?php

namespace Tests\Feature\Financial;

use App\Domain\Financial\Support\AllocationCalculator;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationRateLookupByDateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $brl = Currency::create([
            'code' => 'BRL', 'name' => 'Brazilian Real', 'name_plural' => 'Brazilian Reais',
            'symbol' => 'R$', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);

        // Taxa vigente na data do pagamento retroativo…
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $brl->id,
            'rate' => 5.0, 'inverse_rate' => 1 / 5.0,
            'date' => '2026-07-01',
            'status' => ExchangeRateStatus::APPROVED,
        ]);
        // …e uma taxa mais nova aprovada depois.
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $brl->id,
            'rate' => 5.5, 'inverse_rate' => 1 / 5.5,
            'date' => '2026-07-10',
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    public function test_lookup_uses_rate_effective_on_the_given_payment_date(): void
    {
        $resolved = AllocationCalculator::lookupRateWithDate('BRL', 'USD', '2026-07-05');

        $this->assertEqualsWithDelta(1 / 5.0, $resolved['rate'], 1e-8, 'must use the 07-01 rate, not the newer 07-10 one');
        $this->assertSame('2026-07-01', $resolved['date']);
    }

    public function test_lookup_without_date_still_uses_the_latest_rate(): void
    {
        $resolved = AllocationCalculator::lookupRateWithDate('BRL', 'USD');

        $this->assertEqualsWithDelta(1 / 5.5, $resolved['rate'], 1e-8);
        $this->assertSame('2026-07-10', $resolved['date']);
    }

    public function test_lookup_before_any_rate_exists_returns_null(): void
    {
        $resolved = AllocationCalculator::lookupRateWithDate('BRL', 'USD', '2026-06-15');

        $this->assertNull($resolved['rate'], 'no approved rate on or before the payment date — no silent fallback');
        $this->assertNull($resolved['date']);
    }
}
