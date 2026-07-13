<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Actions\RefreshQuotationFxRatesAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Quotations\Models\QuotationItemSupplier;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefreshQuotationFxRatesActionTest extends TestCase
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
        Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'name_plural' => 'Euros',
            'symbol' => '€', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);

        // Current rate: USD→CNY 8.0, so CNY→USD = 0.125.
        ExchangeRate::create([
            'base_currency_id' => $this->usd->id, 'target_currency_id' => $this->cny->id,
            'rate' => 8.0, 'inverse_rate' => 1 / 8.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    private function makeAction(): RefreshQuotationFxRatesAction
    {
        return new RefreshQuotationFxRatesAction(new CurrencyExchangeResolver);
    }

    private function buildQuotation(CommissionType $commissionType = CommissionType::EMBEDDED): Quotation
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        return Quotation::create([
            'reference' => 'Q-FX-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::DRAFT,
            'currency_code' => 'USD',
            'commission_type' => $commissionType,
            'commission_rate' => 0,
        ]);
    }

    private function buildItem(Quotation $quotation, array $attributes = []): QuotationItem
    {
        return QuotationItem::create(array_merge([
            'quotation_id' => $quotation->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 10,
            'unit_cost' => 700000, // 70.0000 in the cost currency
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.15,
            'cost_exchange_rate_captured_at' => today()->subMonth()->toDateString(),
            'commission_rate' => 0,
            'unit_price' => 105000, // 70 * 0.15 = 10.5000
            'sort_order' => 0,
        ], $attributes));
    }

    public function test_refreshes_rate_and_recalculates_price_with_embedded_commission(): void
    {
        $quotation = $this->buildQuotation();
        $item = $this->buildItem($quotation, ['commission_rate' => 10]);

        $result = $this->makeAction()->execute($quotation);

        $item->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertEqualsWithDelta(0.125, (float) $item->cost_exchange_rate, 0.0000001);
        $this->assertSame(today()->subDay()->toDateString(), $item->cost_exchange_rate_captured_at->toDateString());
        // 700000 * 0.125 = 87500 converted; * 1.10 commission = 96250.
        $this->assertSame(96250, $item->unit_price);
    }

    public function test_keeps_unit_price_when_recalculate_is_disabled(): void
    {
        $quotation = $this->buildQuotation();
        $item = $this->buildItem($quotation, ['unit_price' => 123456]);

        $result = $this->makeAction()->execute($quotation, recalculatePrices: false);

        $item->refresh();
        $this->assertSame(1, $result['updated']);
        $this->assertEqualsWithDelta(0.125, (float) $item->cost_exchange_rate, 0.0000001);
        $this->assertSame(123456, $item->unit_price);
    }

    public function test_separate_commission_price_equals_converted_cost(): void
    {
        $quotation = $this->buildQuotation(CommissionType::SEPARATE);
        $item = $this->buildItem($quotation, ['commission_rate' => 10]);

        $this->makeAction()->execute($quotation);

        $item->refresh();
        // Commission is separate, so the item price ignores the item commission rate.
        $this->assertSame(87500, $item->unit_price);
    }

    public function test_skips_items_without_available_rate(): void
    {
        $quotation = $this->buildQuotation();
        $item = $this->buildItem($quotation, [
            'cost_currency_code' => 'EUR',
            'cost_exchange_rate' => 0.9,
            'unit_price' => 630000,
        ]);

        $result = $this->makeAction()->execute($quotation);

        $item->refresh();
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, $result['skipped']);
        $this->assertEqualsWithDelta(0.9, (float) $item->cost_exchange_rate, 0.0000001);
        $this->assertSame(630000, $item->unit_price);
    }

    public function test_ignores_same_currency_items(): void
    {
        $quotation = $this->buildQuotation();
        $item = $this->buildItem($quotation, [
            'cost_currency_code' => 'USD',
            'cost_exchange_rate' => 1,
            'unit_price' => 700000,
        ]);

        $result = $this->makeAction()->execute($quotation);

        $item->refresh();
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(700000, $item->unit_price);
    }

    public function test_refreshes_supplier_alternatives(): void
    {
        $quotation = $this->buildQuotation();
        $item = $this->buildItem($quotation);
        $supplier = Company::factory()->create();

        $alternative = QuotationItemSupplier::create([
            'quotation_item_id' => $item->id,
            'company_id' => $supplier->id,
            'unit_cost' => 800000,
            'currency_code' => 'CNY',
            'cost_exchange_rate' => 0.15,
            'cost_exchange_rate_captured_at' => today()->subMonth()->toDateString(),
        ]);

        $this->makeAction()->execute($quotation);

        $alternative->refresh();
        $this->assertEqualsWithDelta(0.125, (float) $alternative->cost_exchange_rate, 0.0000001);
        $this->assertSame(today()->subDay()->toDateString(), $alternative->cost_exchange_rate_captured_at->toDateString());
    }
}
