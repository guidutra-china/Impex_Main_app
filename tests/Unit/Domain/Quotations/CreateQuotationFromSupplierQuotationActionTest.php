<?php

namespace Tests\Unit\Domain\Quotations;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Actions\CreateQuotationFromSupplierQuotationAction;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Exceptions\QuotationLockedException;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Settings\Enums\ExchangeRateStatus;
use App\Domain\Settings\Models\Currency;
use App\Domain\Settings\Models\ExchangeRate;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateQuotationFromSupplierQuotationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $usd = Currency::create([
            'code' => 'USD', 'name' => 'US Dollar', 'name_plural' => 'US Dollars',
            'symbol' => '$', 'decimal_places' => 2, 'is_base' => true, 'is_active' => true,
        ]);
        $cny = Currency::create([
            'code' => 'CNY', 'name' => 'Chinese Yuan', 'name_plural' => 'Chinese Yuan',
            'symbol' => '¥', 'decimal_places' => 2, 'is_base' => false, 'is_active' => true,
        ]);
        ExchangeRate::create([
            'base_currency_id' => $usd->id, 'target_currency_id' => $cny->id,
            'rate' => 7.0, 'inverse_rate' => 1 / 7.0,
            'date' => today()->subDay()->toDateString(),
            'status' => ExchangeRateStatus::APPROVED,
        ]);
    }

    private function makeAction(): CreateQuotationFromSupplierQuotationAction
    {
        return app(CreateQuotationFromSupplierQuotationAction::class);
    }

    /**
     * Inquiry com 1 item pedido pelo cliente + SQ do fornecedor cotando esse
     * item e oferecendo 1 opção extra que não estava na inquiry.
     *
     * @return array{0: Company, 1: Inquiry, 2: SupplierQuotation, 3: Product, 4: Product}
     */
    private function buildScenario(): array
    {
        $client = Company::factory()->create();
        $supplier = Company::factory()->create();
        $inquiry = Inquiry::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        $asked = Product::factory()->create();
        InquiryItem::create([
            'inquiry_id' => $inquiry->id,
            'product_id' => $asked->id,
            'quantity' => 100,
            'sort_order' => 0,
        ]);

        $sq = SupplierQuotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $supplier->id,
            'currency_code' => 'USD',
            'status' => SupplierQuotationStatus::RECEIVED,
            'incoterm' => Incoterm::FOB,
        ]);

        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $asked->id,
            'quantity' => 100,
            'unit_cost' => 100000,
        ]);

        $extra = Product::factory()->create();
        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $extra->id,
            'description' => 'Opção extra do fornecedor',
            'quantity' => 20,
            'unit_cost' => 200000,
        ]);

        return [$client, $inquiry, $sq, $asked, $extra];
    }

    public function test_extra_supplier_item_reaches_the_quotation_with_commission(): void
    {
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        $this->assertSame(QuotationStatus::DRAFT, $quotation->status);
        $this->assertSame($client->id, $quotation->company_id);
        $this->assertSame($inquiry->id, $quotation->inquiry_id);
        $this->assertSame(2, $quotation->items()->count());

        $extraItem = $quotation->items()->where('product_id', $extra->id)->first();
        $this->assertNotNull($extraItem);
        $this->assertSame(200000, $extraItem->unit_cost);
        // 10% embutidos: 200000 * 1.10
        $this->assertSame(220000, $extraItem->unit_price);
        $this->assertSame($sq->company_id, $extraItem->selected_supplier_id);
    }

    public function test_applies_payment_term_validity_and_incoterm_on_header(): void
    {
        [$client, $inquiry, $sq] = $this->buildScenario();
        $paymentTerm = \App\Domain\Settings\Models\PaymentTerm::create([
            'name' => '30% adiantado / 70% contra cópia do BL',
            'is_active' => true,
        ]);

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
            showSuppliers: true,
            incoterm: Incoterm::FOB,
            paymentTermId: $paymentTerm->id,
            validityDays: 45,
        );

        $this->assertSame($paymentTerm->id, $quotation->payment_term_id);
        $this->assertSame(45, $quotation->validity_days);
        $this->assertSame(today()->addDays(45)->toDateString(), $quotation->valid_until->toDateString());
        $this->assertTrue($quotation->show_suppliers);
        $this->assertSame(Incoterm::FOB, $quotation->incoterm);
    }

    public function test_advances_supplier_quotation_to_under_analysis(): void
    {
        [$client, $inquiry, $sq] = $this->buildScenario();

        $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'USD',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 10,
        );

        $this->assertSame(SupplierQuotationStatus::UNDER_ANALYSIS, $sq->fresh()->status);
    }

    public function test_locked_quotation_without_force_rolls_everything_back(): void
    {
        [$client, $inquiry, $sq, $asked, $extra] = $this->buildScenario();

        Quotation::create([
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'status' => QuotationStatus::SENT,
            'currency_code' => 'USD',
            'commission_type' => CommissionType::EMBEDDED,
            'commission_rate' => 0,
        ]);

        $itemsBefore = $inquiry->items()->count();

        try {
            $this->makeAction()->execute(
                sq: $sq,
                companyId: $client->id,
                contactId: null,
                currencyCode: 'USD',
                commissionType: CommissionType::EMBEDDED,
                commissionRate: 10,
            );
            $this->fail('Expected QuotationLockedException.');
        } catch (QuotationLockedException $e) {
            // esperado
        }

        // Rollback total: nem o item extra na inquiry, nem a transição da SQ.
        $this->assertSame($itemsBefore, $inquiry->fresh()->items()->count());
        $this->assertSame(SupplierQuotationStatus::RECEIVED, $sq->fresh()->status);
        $this->assertSame(1, Quotation::count());
    }

    public function test_currency_override_converts_costs(): void
    {
        [$client, $inquiry, $sq, $asked] = $this->buildScenario();

        $quotation = $this->makeAction()->execute(
            sq: $sq,
            companyId: $client->id,
            contactId: null,
            currencyCode: 'CNY',
            commissionType: CommissionType::EMBEDDED,
            commissionRate: 0,
        );

        $this->assertSame('CNY', $quotation->currency_code);
        $askedItem = $quotation->items()->where('product_id', $asked->id)->first();
        $this->assertSame(700000, $askedItem->unit_price);
        $this->assertSame('USD', $inquiry->fresh()->currency_code);
    }
}
