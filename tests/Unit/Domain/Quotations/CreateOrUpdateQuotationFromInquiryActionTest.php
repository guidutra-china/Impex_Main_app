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
        \App\Domain\Settings\Models\Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
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
}
