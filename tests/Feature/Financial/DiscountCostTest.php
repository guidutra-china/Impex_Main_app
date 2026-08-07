<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscountCostTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $supplier;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->supplier = Company::create(['name' => 'Shenzhen Maker', 'status' => 'active']);
        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
        ]);
    }

    public function test_discount_enum_case_is_complete(): void
    {
        $type = AdditionalCostType::DISCOUNT;

        $this->assertSame('discount', $type->value);
        $this->assertSame('Discount', $type->getEnglishLabel());
        $this->assertSame('warning', $type->getColor());
        $this->assertNotNull($type->getIcon());
        $this->assertSame('Desconto', __('enums.additional_cost_type.discount', [], 'pt_BR'));
        $this->assertSame('Discount', __('enums.additional_cost_type.discount', [], 'en'));
    }

    /** @param array<string, mixed> $overrides */
    private function makeDiscount(array $overrides = []): AdditionalCost
    {
        return $this->pi->additionalCosts()->create(array_merge([
            'cost_type' => AdditionalCostType::DISCOUNT->value,
            'description' => '5% goodwill discount',
            'amount' => 2_000_000, // digitado positivo: USD 200.00
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 2_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ], $overrides));
    }

    public function test_discount_amounts_are_normalized_negative_on_save(): void
    {
        $cost = $this->makeDiscount()->refresh();

        $this->assertSame(-2_000_000, $cost->amount);
        $this->assertSame(-2_000_000, $cost->amount_in_document_currency);

        // Re-save não dupla-nega.
        $cost->update(['description' => 'edited']);
        $this->assertSame(-2_000_000, $cost->refresh()->amount);
    }

    public function test_non_discount_amounts_are_untouched(): void
    {
        $cost = $this->pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::OTHER->value,
            'description' => 'Logo',
            'amount' => 2_000_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 2_000_000,
            'billable_to' => BillableTo::CLIENT->value,
            'status' => AdditionalCostStatus::PENDING->value,
        ]);

        $this->assertSame(2_000_000, $cost->refresh()->amount);
    }

    public function test_discount_reduces_pi_grand_total(): void
    {
        \Database\Factories\ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $this->pi->id,
            'quantity' => 10,
            'unit_price' => 1_000_000, // 10 × 100.00 = 1000.00
        ]);
        $this->makeDiscount(); // −200.00

        $pi = $this->pi->fresh(['items', 'additionalCosts']);
        $this->assertSame(10_000_000, $pi->subtotal);
        $this->assertSame(8_000_000, $pi->grand_total);
    }

    private function syncCosts(): void
    {
        $action = new GeneratePaymentScheduleAction;
        $method = new \ReflectionMethod($action, 'syncAdditionalCosts');
        $method->invoke($action, $this->pi);
    }

    private function creditPsiFor(AdditionalCost $cost): ?PaymentScheduleItem
    {
        return PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->where('is_credit', true)
            ->first();
    }

    public function test_client_discount_creates_positive_credit_psi_on_pi(): void
    {
        $cost = $this->makeDiscount();
        $this->syncCosts();

        $psi = $this->creditPsiFor($cost);
        $this->assertNotNull($psi);
        $this->assertSame(2_000_000, $psi->amount, 'PSI sempre positivo (abs).');
        $this->assertTrue($psi->is_credit);
        $this->assertSame(ProformaInvoice::class, $psi->payable_type);
        $this->assertSame($this->pi->id, $psi->payable_id);
        $this->assertStringStartsWith('Discount:', $psi->label);
    }

    public function test_supplier_discount_credit_anchors_on_po_with_pi_fallback(): void
    {
        $cost = $this->makeDiscount([
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
        ]);

        // Sem PO: fallback na PI.
        $this->syncCosts();
        $this->assertSame(ProformaInvoice::class, $this->creditPsiFor($cost)->payable_type);

        $po = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);

        $this->syncCosts();
        $psi = $this->creditPsiFor($cost);
        $this->assertSame(PurchaseOrder::class, $psi->payable_type);
        $this->assertSame($po->id, $psi->payable_id);
        $this->assertSame(2_000_000, $psi->amount);
    }

    public function test_legacy_supplier_credit_cost_keeps_positive_psi(): void
    {
        // Regressão da uniformização abs(): custo supplier-billable legado (positivo).
        $cost = $this->pi->additionalCosts()->create([
            'cost_type' => AdditionalCostType::TESTING->value,
            'description' => 'Quality deduction',
            'amount' => 1_500_000,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => 1_500_000,
            'billable_to' => BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
            'status' => AdditionalCostStatus::PENDING->value,
        ]);

        $this->syncCosts();

        $psi = $this->creditPsiFor($cost);
        $this->assertSame(1_500_000, $psi->amount);
        $this->assertStringStartsWith('Credit:', $psi->label);
    }
}
