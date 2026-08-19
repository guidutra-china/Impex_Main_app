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

    public function test_new_quotation_gets_standard_sequential_reference(): void
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

        $this->assertMatchesRegularExpression(
            '/^QT-'.now()->year.'-\d+$/',
            $quotation->reference,
            'reference must follow the QT-YYYY-NNNNN sequence, not the legacy Q-Ymd-rand format'
        );
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

    public function test_all_alternatives_are_persisted_as_quotation_item_suppliers(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplierA = Company::factory()->create();
        $supplierB = Company::factory()->create();
        $sqA = $this->buildSqWith($inquiry, $supplierA, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 80000],
        ]);
        $sqB = $this->buildSqWith($inquiry, $supplierB, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sqA->id, $sqB->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $item = $quotation->items->first();
        $this->assertSame($supplierB->id, $item->selected_supplier_id, 'lowest-cost supplier elected');
        $this->assertCount(2, $item->suppliers, 'both alternatives stored');
    }

    public function test_same_supplier_quoting_a_product_twice_does_not_violate_unique_constraint(): void
    {
        // Same supplier company quotes the SAME product across two separate SQs.
        // Both alternatives share (quotation_item_id, company_id) → must dedupe, keeping the cheapest.
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sqHigh = $this->buildSqWith($inquiry, $supplier, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 80000],
        ]);
        $sqLow = $this->buildSqWith($inquiry, $supplier, 'CNY', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 70000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sqHigh->id, $sqLow->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $item = $quotation->items->first();
        $this->assertCount(1, $item->suppliers, 'one supplier row per company');
        $this->assertSame($supplier->id, $item->suppliers->first()->company_id);
        $this->assertSame(70000, $item->suppliers->first()->unit_cost, 'cheapest quote kept');
    }

    public function test_sent_with_force_new_version_creates_v2_and_snapshots_v1(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 1000],
        ]);

        $v1 = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );
        $v1->update(['status' => QuotationStatus::SENT]);

        $v2 = $this->makeAction()->execute(
            inquiry: $inquiry->fresh(),
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            forceNewVersion: true,
        );

        $this->assertNotSame($v1->id, $v2->id, 'new Quotation row');
        $this->assertSame(2, $v2->version);
        $this->assertSame(QuotationStatus::DRAFT, $v2->status);
        $this->assertDatabaseHas('quotation_versions', [
            'quotation_id' => $v1->id,
            'version' => 1,
        ]);
    }

    public function test_item_overrides_win_over_resolver_defaults(): void
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
            itemOverrides: [
                $items[0]->id => [
                    'unit_cost' => 7.5,                   // 75000 minor units (×10000)
                    'cost_currency_code' => 'CNY',
                    'cost_exchange_rate' => 0.20,         // user-overridden FX
                    'commission_rate' => 30,
                    'unit_price' => 1.95,                 // 19500 minor units (= 75000 × 0.20 × 1.30)
                ],
            ],
        );

        $item = $quotation->items->first();
        $this->assertSame(75000, $item->unit_cost);
        $this->assertEqualsWithDelta(0.20, (float) $item->cost_exchange_rate, 0.001);
        $this->assertSame(19500, $item->unit_price);
    }

    public function test_rerun_drops_quotation_items_whose_product_is_no_longer_in_inquiry(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(itemCount: 2);
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 1000],
            ['product_id' => $items[1]->product_id, 'unit_cost' => 2000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );
        $this->assertCount(2, $quotation->items);

        // User removes the second item from the inquiry, then re-runs.
        $items[1]->delete();

        $quotation2 = $this->makeAction()->execute(
            inquiry: $inquiry->fresh(),
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $this->assertSame($quotation->id, $quotation2->id);
        $this->assertCount(1, $quotation2->items, 'orphan QuotationItem should be deleted on re-run');
        $this->assertSame($items[0]->product_id, $quotation2->items->first()->product_id);
    }

    public function test_description_is_inherited_from_inquiry_and_manual_edit_is_preserved(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $inquiry->update(['description' => 'Fitness equipment March order']);
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 1000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry->fresh('items'),
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $this->assertSame('Fitness equipment March order', $quotation->description);

        // A manual edit on the quotation survives a re-run.
        $quotation->update(['description' => 'Custom title']);

        $quotation2 = $this->makeAction()->execute(
            inquiry: $inquiry->fresh('items'),
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $this->assertSame($quotation->id, $quotation2->id);
        $this->assertSame('Custom title', $quotation2->description);
    }

    public function test_embedded_commission_rate_is_persisted_on_header(): void
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
            commissionRate: 10,
            showSuppliers: false,
        );

        $this->assertEqualsWithDelta(10.0, (float) $quotation->commission_rate, 0.01);
        $this->assertEqualsWithDelta(10.0, (float) $quotation->items->first()->commission_rate, 0.01);
    }

    public function test_header_incoterm_is_persisted_and_defaults_items(): void
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
            commissionRate: 0,
            showSuppliers: false,
            incoterm: \App\Domain\Quotations\Enums\Incoterm::FOB,
        );

        $this->assertSame(\App\Domain\Quotations\Enums\Incoterm::FOB, $quotation->incoterm);
        // The SQ has no incoterm, so items inherit the quotation default.
        $this->assertSame(\App\Domain\Quotations\Enums\Incoterm::FOB, $quotation->items->first()->incoterm);
    }

    public function test_item_incoterm_is_copied_from_source_supplier_quotation(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems();
        $supplier = Company::factory()->create();
        $sq = $this->buildSqWith($inquiry, $supplier, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 1000],
        ]);
        $sq->update(['incoterm' => 'EXW']);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$sq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            incoterm: \App\Domain\Quotations\Enums\Incoterm::FOB,
        );

        // The SQ incoterm wins over the quotation default on the item.
        $this->assertSame(\App\Domain\Quotations\Enums\Incoterm::EXW, $quotation->items->first()->incoterm);
        $this->assertSame(\App\Domain\Quotations\Enums\Incoterm::FOB, $quotation->incoterm);
    }

    public function test_preferred_supplier_quotation_wins_even_when_more_expensive(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(1);
        $cheap = Company::factory()->create();
        $expensive = Company::factory()->create();

        $cheapSq = $this->buildSqWith($inquiry, $cheap, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 100000],
        ]);
        $expensiveSq = $this->buildSqWith($inquiry, $expensive, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 250000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$cheapSq->id, $expensiveSq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            preferredSupplierQuotationId: $expensiveSq->id,
        );

        $item = $quotation->items()->first();
        $this->assertSame($expensive->id, $item->selected_supplier_id);
        $this->assertSame(250000, $item->unit_cost);
        // A SQ mais barata continua disponível como alternativa.
        $this->assertSame(2, $item->suppliers()->count());
    }

    public function test_without_preferred_id_the_cheapest_still_wins(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(1);
        $cheap = Company::factory()->create();
        $expensive = Company::factory()->create();

        $cheapSq = $this->buildSqWith($inquiry, $cheap, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 100000],
        ]);
        $expensiveSq = $this->buildSqWith($inquiry, $expensive, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 250000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$cheapSq->id, $expensiveSq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
        );

        $item = $quotation->items()->first();
        $this->assertSame($cheap->id, $item->selected_supplier_id);
        $this->assertSame(100000, $item->unit_cost);
    }

    public function test_preferred_id_falls_back_to_cheapest_when_it_does_not_quote_the_item(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(1);
        $cheap = Company::factory()->create();
        $other = Company::factory()->create();
        $otherProduct = \App\Domain\Catalog\Models\Product::factory()->create();

        $cheapSq = $this->buildSqWith($inquiry, $cheap, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 100000],
        ]);
        // Esta SQ cota outro produto: não tem alternativa para o item da inquiry.
        $otherSq = $this->buildSqWith($inquiry, $other, 'USD', [
            ['product_id' => $otherProduct->id, 'unit_cost' => 900000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$cheapSq->id, $otherSq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            preferredSupplierQuotationId: $otherSq->id,
        );

        $item = $quotation->items()->first();
        $this->assertSame($cheap->id, $item->selected_supplier_id);
    }

    public function test_preferred_supplier_quotation_applies_per_item_not_globally(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(2);
        $cheap = Company::factory()->create();
        $preferred = Company::factory()->create();

        // Cotação barata: cobre os dois itens.
        $cheapSq = $this->buildSqWith($inquiry, $cheap, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 100000],
            ['product_id' => $items[1]->product_id, 'unit_cost' => 50000],
        ]);
        // Cotação preferida: só cota o primeiro item, e mais caro.
        $preferredSq = $this->buildSqWith($inquiry, $preferred, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 250000],
        ]);

        $quotation = $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$cheapSq->id, $preferredSq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            preferredSupplierQuotationId: $preferredSq->id,
        );

        $byProduct = $quotation->items()->get()->keyBy('product_id');
        // Item 0: a SQ preferida cota este item, então ela vence mesmo mais cara.
        $this->assertSame($preferred->id, $byProduct[$items[0]->product_id]->selected_supplier_id);
        $this->assertSame(250000, $byProduct[$items[0]->product_id]->unit_cost);
        // Item 1: a SQ preferida não cota este item, então cai para a mais barata.
        $this->assertSame($cheap->id, $byProduct[$items[1]->product_id]->selected_supplier_id);
        $this->assertSame(50000, $byProduct[$items[1]->product_id]->unit_cost);
    }

    public function test_preferred_id_outside_the_pool_throws(): void
    {
        [$client, $inquiry, $items] = $this->buildInquiryWithItems(1);
        $inPool = Company::factory()->create();
        $outsidePool = Company::factory()->create();

        $inPoolSq = $this->buildSqWith($inquiry, $inPool, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 100000],
        ]);
        $outsidePoolSq = $this->buildSqWith($inquiry, $outsidePool, 'USD', [
            ['product_id' => $items[0]->product_id, 'unit_cost' => 200000],
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeAction()->execute(
            inquiry: $inquiry,
            supplierQuotationIds: [$inPoolSq->id],
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
            showSuppliers: false,
            preferredSupplierQuotationId: $outsidePoolSq->id,
        );
    }
}
