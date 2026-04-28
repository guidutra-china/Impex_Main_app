<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Actions\CreateOrUpdateQuotationFromInquiryAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Services\CurrencyExchangeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateOrUpdateQuotationFromInquiryActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $usd = \App\Domain\Settings\Models\Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = \App\Domain\Settings\Models\Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        $eur = \App\Domain\Settings\Models\Currency::create([
            'code' => 'EUR', 'name' => 'Euro', 'name_plural' => 'Euros',
            'symbol' => '€', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        \App\Domain\Settings\Models\ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => \App\Domain\Settings\Enums\ExchangeRateStatus::APPROVED,
        ]);
        \App\Domain\Settings\Models\ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $eur->id,
            'rate' => 0.92, 'inverse_rate' => 1 / 0.92,
            'date' => today()->subDay()->toDateString(),
            'status' => \App\Domain\Settings\Enums\ExchangeRateStatus::APPROVED,
        ]);
    }

    private function makeAction(): CreateOrUpdateQuotationFromInquiryAction
    {
        return new CreateOrUpdateQuotationFromInquiryAction(new CurrencyExchangeResolver);
    }

    private function buildInquiryWithItems(int $itemCount = 1, string $currency = 'USD'): array
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'currency_code' => $currency,
        ]);
        $items = [];
        for ($i = 0; $i < $itemCount; $i++) {
            $product = \App\Domain\Catalog\Models\Product::factory()->create();
            $items[] = \App\Domain\Inquiries\Models\InquiryItem::create([
                'inquiry_id' => $inquiry->id,
                'product_id' => $product->id,
                'quantity' => 10,
                'sort_order' => $i,
            ]);
        }

        return [$client, $inquiry, $items];
    }

    private function buildSqWith(
        Inquiry $inquiry,
        Company $supplier,
        string $currency,
        array $items,
    ): \App\Domain\SupplierQuotations\Models\SupplierQuotation {
        $sq = \App\Domain\SupplierQuotations\Models\SupplierQuotation::create([
            'reference' => 'SQ-'.uniqid(),
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => $currency,
            'status' => \App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus::RECEIVED,
        ]);
        foreach ($items as $i) {
            \App\Domain\SupplierQuotations\Models\SupplierQuotationItem::create([
                'supplier_quotation_id' => $sq->id,
                'product_id' => $i['product_id'],
                'quantity' => 10,
                'unit_cost' => $i['unit_cost'],
            ]);
        }

        return $sq;
    }

    public function test_throws_when_existing_quotation_is_sent_without_force(): void
    {
        $client = Company::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        Quotation::create([
            'reference' => 'Q-LOCK-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::SENT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        $this->expectException(QuotationLockedException::class);

        $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            forceNewVersion: false,
        );
    }

    public function test_single_currency_creates_quotation_with_rate_1(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 1000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 20,
            showSuppliers: false,
        );

        $this->assertCount(1, $quotation->items);
        $item = $quotation->items->first();
        $this->assertSame('USD', $item->cost_currency_code);
        $this->assertEqualsWithDelta(1.0, (float) $item->cost_exchange_rate, 0.0001);
        $this->assertSame(1000, $item->unit_cost);
        // EMBEDDED: 1000 × 1.20 = 1200
        $this->assertSame(1200, $item->unit_price);
    }

    public function test_cross_currency_cny_to_usd_snapshots_rate(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $item = $quotation->items->first();
        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertEqualsWithDelta(1 / 7.0, (float) $item->cost_exchange_rate, 0.0001);
        $this->assertSame(70000, $item->unit_cost);
        // converted: 70000 × 1/7 ≈ 10000 USD minor units
        $this->assertEqualsWithDelta(10000, $item->unit_price, 1);
    }

    public function test_multi_currency_aggregation_each_item_has_own_rate(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(itemCount: 2);
        $supplierA = Company::factory()->create();
        $supplierB = Company::factory()->create();
        $sqCny = $this->buildSqWith($inquiry, $supplierA, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);
        $sqEur = $this->buildSqWith($inquiry, $supplierB, 'EUR', [
            ['product_id' => $items[1]->product_id, 'unit_cost' => 9200],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sqCny->id, $sqEur->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $byProduct = $quotation->items->keyBy('product_id');
        $this->assertSame('CNY', $byProduct[$items[0]->product_id]->cost_currency_code);
        $this->assertSame('EUR', $byProduct[$items[1]->product_id]->cost_currency_code);
    }

    public function test_rerun_in_draft_refreshes_unit_cost_and_rate_but_preserves_commission_override(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
            showSuppliers: false,
        );
        $item = $quotation->items->first();
        $item->update(['commission_rate' => 25.0]); // user manual override
        $sq->items->first()->update(['unit_cost' => 80000]); // supplier re-quote

        $quotation2 = $this->makeAction()->execute(
            inquiry: $inquiry->fresh(),
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
            showSuppliers: false,
        );

        $refreshed = $quotation2->items->first();
        $this->assertSame($quotation->id, $quotation2->id, 'should update in place, not create new');
        $this->assertSame(80000, $refreshed->unit_cost, 'unit_cost refreshed from SQ');
        $this->assertEqualsWithDelta(25.0, (float) $refreshed->commission_rate, 0.01, 'commission_rate override preserved');
    }
}
