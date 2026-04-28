<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Quotations\Actions\PromoteQuotationItemSupplierAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\Quotations\Models\QuotationItemSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromoteQuotationItemSupplierActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_make_this_selected_swaps_selected_supplier_and_fx_fields(): void
    {
        [$quotation, $item, $alt, $supplierB] = $this->buildScenario(QuotationStatus::DRAFT);

        app(PromoteQuotationItemSupplierAction::class)->execute($alt);

        $item->refresh();
        $this->assertSame($supplierB->id, $item->selected_supplier_id);
        $this->assertSame(60000, $item->unit_cost);
        $this->assertSame('CNY', $item->cost_currency_code);
        $this->assertSame('0.14000000', (string) $item->cost_exchange_rate);
        // EMBEDDED with 0% commission and 0.14 rate: 60000 * 0.14 = 8400 (rounded)
        $this->assertSame(8400, $item->unit_price);
    }

    public function test_throws_when_quotation_is_not_draft(): void
    {
        [$quotation, $item, $alt] = $this->buildScenario(QuotationStatus::SENT);

        $this->expectException(QuotationLockedException::class);
        app(PromoteQuotationItemSupplierAction::class)->execute($alt);
    }

    /**
     * @return array{0: Quotation, 1: QuotationItem, 2: QuotationItemSupplier, 3: Company}
     */
    private function buildScenario(QuotationStatus $status): array
    {
        $client = Company::factory()->create();
        $supplierA = Company::factory()->create();
        $supplierB = Company::factory()->create();
        $product = Product::factory()->create();
        $inquiry = Inquiry::factory()->create(['company_id' => $client->id]);

        $quotation = Quotation::create([
            'reference' => 'Q-MS-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => $status,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        $item = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'quantity' => 10,
            'unit_cost' => 70000,
            'cost_currency_code' => 'CNY',
            'cost_exchange_rate' => 0.14,
            'unit_price' => 9800,
            'commission_rate' => 0,
            'selected_supplier_id' => $supplierA->id,
        ]);

        $alt = QuotationItemSupplier::create([
            'quotation_item_id' => $item->id,
            'company_id' => $supplierB->id,
            'unit_cost' => 60000,
            'currency_code' => 'CNY',
            'cost_exchange_rate' => 0.14,
        ]);

        return [$quotation, $item, $alt, $supplierB];
    }
}
