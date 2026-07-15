<?php

namespace Tests\Unit\Domain\ProformaInvoices;

use App\Domain\Catalog\Models\Product;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\ProformaInvoices\Actions\CreateQuotationCommissionCostsAction;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateQuotationCommissionCostsActionTest extends TestCase
{
    use RefreshDatabase;

    private function makeAction(): CreateQuotationCommissionCostsAction
    {
        return new CreateQuotationCommissionCostsAction;
    }

    /**
     * @return array{0: ProformaInvoice, 1: Quotation, 2: QuotationItem}
     */
    private function buildPiWithSeparateQuotation(float $rate = 10.0): array
    {
        $quotation = Quotation::factory()->create([
            'commission_type' => CommissionType::SEPARATE->value,
            'commission_rate' => $rate,
        ]);

        $quotationItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 10,
            'unit_cost' => 1000,
            'unit_price' => 1000,
            'sort_order' => 1,
        ]);

        $pi = ProformaInvoice::factory()->create([
            'company_id' => $quotation->company_id,
            'currency_code' => 'USD',
        ]);
        $pi->quotations()->attach($quotation->id);

        return [$pi, $quotation, $quotationItem];
    }

    public function test_creates_commission_cost_from_quotation_linked_items(): void
    {
        [$pi, $quotation, $quotationItem] = $this->buildPiWithSeparateQuotation(10.0);

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'quotation_item_id' => $quotationItem->id,
            'product_id' => $quotationItem->product_id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);

        $this->makeAction()->execute($pi, [$quotation->id]);

        $cost = $pi->additionalCosts()->where('cost_type', AdditionalCostType::COMMISSION)->first();
        $this->assertNotNull($cost, 'commission AdditionalCost should be created');
        // 10 × 1000 = 10000 base → 10% = 1000
        $this->assertSame(1000, $cost->amount);
        $this->assertStringContainsString($quotation->reference, $cost->notes);
    }

    public function test_falls_back_to_product_match_when_items_are_not_linked_to_quotation(): void
    {
        [$pi, $quotation, $quotationItem] = $this->buildPiWithSeparateQuotation(20.0);

        // Items created from the inquiry flow: no quotation_item link, same product
        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'quotation_item_id' => null,
            'product_id' => $quotationItem->product_id,
            'quantity' => 5,
            'unit_price' => 2000,
        ]);

        $this->makeAction()->execute($pi, [$quotation->id]);

        $cost = $pi->additionalCosts()->where('cost_type', AdditionalCostType::COMMISSION)->first();
        $this->assertNotNull($cost, 'fallback by product_id should still create the commission cost');
        // 5 × 2000 = 10000 base → 20% = 2000
        $this->assertSame(2000, $cost->amount);
    }

    public function test_embedded_commission_creates_no_cost(): void
    {
        [$pi, $quotation, $quotationItem] = $this->buildPiWithSeparateQuotation(15.0);
        $quotation->update(['commission_type' => CommissionType::EMBEDDED->value]);

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'quotation_item_id' => $quotationItem->id,
            'product_id' => $quotationItem->product_id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);

        $this->makeAction()->execute($pi, [$quotation->id]);

        $this->assertSame(0, $pi->additionalCosts()->count());
    }

    public function test_is_idempotent_for_the_same_quotation(): void
    {
        [$pi, $quotation, $quotationItem] = $this->buildPiWithSeparateQuotation(10.0);

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'quotation_item_id' => $quotationItem->id,
            'product_id' => $quotationItem->product_id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);

        $this->makeAction()->execute($pi, [$quotation->id]);
        $this->makeAction()->execute($pi, [$quotation->id]);

        $this->assertSame(1, $pi->additionalCosts()->where('cost_type', AdditionalCostType::COMMISSION)->count());
    }

    public function test_no_cost_when_pi_has_no_matching_items(): void
    {
        [$pi, $quotation] = $this->buildPiWithSeparateQuotation(10.0);

        ProformaInvoiceItem::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'quotation_item_id' => null,
            'product_id' => Product::factory()->create()->id,
            'quantity' => 10,
            'unit_price' => 1000,
        ]);

        $this->makeAction()->execute($pi, [$quotation->id]);

        $this->assertSame(0, $pi->additionalCosts()->count());
    }
}
