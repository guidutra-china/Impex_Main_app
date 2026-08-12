<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\CreatePoDiscountAction;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Atalho "Lançar desconto" da tela da PO: o custo (tipo Desconto, billable
 * SUPPLIER) nasce na PI do processo e o crédito ancora na PO certa — mesmo
 * numa PI multi-fornecedor.
 */
class CreatePoDiscountActionTest extends TestCase
{
    use RefreshDatabase;

    private ProformaInvoice $pi;

    private Company $supplierA;

    private Company $supplierB;

    private PurchaseOrder $poA;

    private PurchaseOrder $poB;

    protected function setUp(): void
    {
        parent::setUp();

        $client = Company::create(['name' => 'Buyer Co', 'status' => 'active']);
        $this->supplierA = Company::create(['name' => 'Supplier A', 'status' => 'active']);
        $this->supplierB = Company::create(['name' => 'Supplier B', 'status' => 'active']);

        $this->pi = ProformaInvoice::factory()->create([
            'company_id' => $client->id,
            'currency_code' => 'USD',
        ]);

        $this->poA = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierA->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
        ]);

        $this->poB = PurchaseOrder::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierB->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-02',
        ]);
    }

    private function creditPsiFor(AdditionalCost $cost): ?PaymentScheduleItem
    {
        return PaymentScheduleItem::where('source_type', AdditionalCost::class)
            ->where('source_id', $cost->id)
            ->where('is_credit', true)
            ->first();
    }

    public function test_po_discount_creates_cost_on_pi_and_credit_on_that_po(): void
    {
        $cost = app(CreatePoDiscountAction::class)->execute($this->poA, 1_500_000, 'Desconto negociado', 5.0);

        $cost->refresh();
        $this->assertSame(AdditionalCostType::DISCOUNT, $cost->cost_type);
        $this->assertSame(BillableTo::SUPPLIER, $cost->billable_to);
        $this->assertSame($this->supplierA->id, $cost->supplier_company_id);
        $this->assertSame(-1_500_000, $cost->amount, 'Sinal normalizado pelo model.');
        $this->assertSame(ProformaInvoice::class, $cost->costable_type, 'Custo vive na PI do processo.');
        $this->assertStringContainsString('5%', $cost->notes);

        $psi = $this->creditPsiFor($cost);
        $this->assertNotNull($psi);
        $this->assertSame(1_500_000, $psi->amount);
        $this->assertTrue($psi->is_credit);
        $this->assertStringStartsWith('Discount:', $psi->label);
    }

    public function test_credit_anchors_on_the_matching_supplier_po_not_the_first(): void
    {
        // Desconto lançado a partir da PO-B: numa PI multi-fornecedor, o
        // crédito NÃO pode cair na PO-A (primeira PO da PI).
        $cost = app(CreatePoDiscountAction::class)->execute($this->poB, 1_000_000, 'Desconto fornecedor B');

        $psi = $this->creditPsiFor($cost);
        $this->assertSame(PurchaseOrder::class, $psi->payable_type);
        $this->assertSame($this->poB->id, $psi->payable_id, 'Crédito ancora na PO do fornecedor do custo.');
    }
}
