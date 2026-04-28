<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuotationItemMarginTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(int $unitCost, ?string $costCurrency, ?float $rate, int $unitPrice): QuotationItem
    {
        $client = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $quotation = Quotation::create([
            'reference' => 'Q-TEST-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        return QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => $unitCost,
            'cost_currency_code' => $costCurrency,
            'cost_exchange_rate' => $rate,
            'unit_price' => $unitPrice,
            'commission_rate' => 0,
        ]);
    }

    public function test_converted_unit_cost_returns_raw_when_currency_matches_quotation(): void
    {
        $item = $this->makeItem(unitCost: 1000, costCurrency: 'USD', rate: 1.0, unitPrice: 1500);
        $this->assertSame(1000, $item->converted_unit_cost);
    }

    public function test_converted_unit_cost_returns_raw_when_currency_is_null_legacy(): void
    {
        $item = $this->makeItem(unitCost: 1000, costCurrency: null, rate: null, unitPrice: 1500);
        $this->assertSame(1000, $item->converted_unit_cost);
    }

    public function test_converted_unit_cost_applies_rate_when_currencies_differ(): void
    {
        // 10000 CNY × 0.14 = 1400 USD (in minor units).
        $item = $this->makeItem(unitCost: 10000, costCurrency: 'CNY', rate: 0.14, unitPrice: 2000);
        $this->assertSame(1400, $item->converted_unit_cost);
    }

    public function test_converted_cost_total_multiplies_by_quantity(): void
    {
        $item = $this->makeItem(unitCost: 10000, costCurrency: 'CNY', rate: 0.14, unitPrice: 2000);
        $this->assertSame(14000, $item->converted_cost_total); // 1400 × 10
    }

    public function test_margin_uses_converted_cost(): void
    {
        // converted cost = 1400, price = 2000 → margin ≈ 42.86%
        $item = $this->makeItem(unitCost: 10000, costCurrency: 'CNY', rate: 0.14, unitPrice: 2000);
        $this->assertEqualsWithDelta(42.86, $item->margin, 0.01);
    }

    public function test_margin_zero_when_converted_cost_zero(): void
    {
        $item = $this->makeItem(unitCost: 0, costCurrency: 'USD', rate: 1.0, unitPrice: 100);
        $this->assertSame(0.0, $item->margin);
    }
}
