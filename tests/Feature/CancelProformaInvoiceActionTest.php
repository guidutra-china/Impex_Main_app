<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\Planning\Models\ShipmentPlanItem;
use App\Domain\ProformaInvoices\Actions\CancelProformaInvoiceAction;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CancelProformaInvoiceActionTest extends TestCase
{
    use RefreshDatabase;

    private CancelProformaInvoiceAction $action;
    private Company $clientCompany;
    private Company $supplierCompany;
    private Inquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new CancelProformaInvoiceAction();

        $this->clientCompany = Company::create(['name' => 'Test Client', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        $this->supplierCompany = Company::create(['name' => 'China Supplier', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-TEST-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);
    }

    private function createPI(string $status = 'confirmed'): ProformaInvoice
    {
        $pi = ProformaInvoice::create([
            'reference' => 'PI-TEST-' . uniqid(),
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->clientCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => $status,
        ]);

        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Test Product',
            'quantity' => 100,
            'unit_price' => 100_0000,
            'unit_cost' => 50_0000,
        ]);

        $pi->load('items');

        return $pi;
    }

    private function createPOForPI(ProformaInvoice $pi, string $status = 'draft'): PurchaseOrder
    {
        return PurchaseOrder::create([
            'reference' => 'PO-TEST-' . uniqid(),
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'currency_code' => 'USD',
            'status' => $status,
        ]);
    }

    private function createShipmentPlanForPI(ProformaInvoice $pi, string $status = 'draft'): ShipmentPlan
    {
        $plan = ShipmentPlan::create([
            'reference' => 'SPL-TEST-' . uniqid(),
            'supplier_company_id' => $this->supplierCompany->id,
            'status' => $status,
            'currency_code' => 'USD',
            'planned_shipment_date' => '2026-06-01',
        ]);

        ShipmentPlanItem::create([
            'shipment_plan_id' => $plan->id,
            'proforma_invoice_item_id' => $pi->items->first()->id,
            'quantity' => 50,
        ]);

        return $plan;
    }

    // === Tests ===

    public function test_cancel_transitions_pi_to_cancelled(): void
    {
        $pi = $this->createPI('confirmed');

        $result = $this->action->execute($pi, 'Client withdrew.');

        $this->assertEquals(ProformaInvoiceStatus::CANCELLED, $result->status);
    }

    public function test_cancel_cancels_draft_purchase_orders(): void
    {
        $pi = $this->createPI('confirmed');
        $po = $this->createPOForPI($pi, 'draft');

        $this->action->execute($pi);

        $po->refresh();
        $this->assertEquals(PurchaseOrderStatus::CANCELLED, $po->status);
    }

    public function test_cancel_cancels_sent_purchase_orders(): void
    {
        $pi = $this->createPI('confirmed');
        $po = $this->createPOForPI($pi, 'sent');

        $this->action->execute($pi);

        $po->refresh();
        $this->assertEquals(PurchaseOrderStatus::CANCELLED, $po->status);
    }

    public function test_cancel_cancels_in_production_purchase_orders(): void
    {
        $pi = $this->createPI('confirmed');
        $po = $this->createPOForPI($pi, 'in_production');

        $this->action->execute($pi);

        $po->refresh();
        $this->assertEquals(PurchaseOrderStatus::CANCELLED, $po->status);
    }

    public function test_cancel_preserves_shipped_purchase_orders(): void
    {
        $pi = $this->createPI('confirmed');
        $po = $this->createPOForPI($pi, 'shipped');

        $this->action->execute($pi);

        $po->refresh();
        $this->assertEquals(PurchaseOrderStatus::SHIPPED, $po->status);
    }

    public function test_cancel_preserves_completed_purchase_orders(): void
    {
        $pi = $this->createPI('confirmed');
        $po = $this->createPOForPI($pi, 'completed');

        $this->action->execute($pi);

        $po->refresh();
        $this->assertEquals(PurchaseOrderStatus::COMPLETED, $po->status);
    }

    public function test_cancel_cancels_draft_shipment_plans(): void
    {
        $pi = $this->createPI('confirmed');
        $plan = $this->createShipmentPlanForPI($pi, 'draft');

        $this->action->execute($pi);

        $plan->refresh();
        $this->assertEquals(ShipmentPlanStatus::CANCELLED, $plan->status);
    }

    public function test_cancel_cancels_confirmed_shipment_plans(): void
    {
        $pi = $this->createPI('confirmed');
        $plan = $this->createShipmentPlanForPI($pi, 'confirmed');

        $this->action->execute($pi);

        $plan->refresh();
        $this->assertEquals(ShipmentPlanStatus::CANCELLED, $plan->status);
    }

    public function test_cancel_preserves_shipped_shipment_plans(): void
    {
        $pi = $this->createPI('confirmed');
        $plan = $this->createShipmentPlanForPI($pi, 'shipped');

        $this->action->execute($pi);

        $plan->refresh();
        $this->assertEquals(ShipmentPlanStatus::SHIPPED, $plan->status);
    }

    public function test_cancel_waives_pending_payment_schedule_items(): void
    {
        $term = PaymentTerm::create(['name' => 'Test 30/70', 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id,
            'percentage' => 30,
            'days' => 0,
            'calculation_base' => CalculationBase::ORDER_DATE,
            'sort_order' => 1,
        ]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id,
            'percentage' => 70,
            'days' => 30,
            'calculation_base' => CalculationBase::SHIPMENT_DATE,
            'sort_order' => 2,
        ]);

        $pi = $this->createPI('confirmed');
        $pi->update(['payment_term_id' => $term->id]);

        (new GeneratePaymentScheduleAction())->execute($pi);

        $this->action->execute($pi);

        $items = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->get();

        $this->assertCount(2, $items);
        $this->assertTrue($items->every(fn ($item) => $item->status === PaymentScheduleStatus::WAIVED));
    }

    public function test_cancel_preserves_paid_payment_schedule_items(): void
    {
        $term = PaymentTerm::create(['name' => 'Test 100%', 'is_active' => true]);
        PaymentTermStage::create([
            'payment_term_id' => $term->id,
            'percentage' => 100,
            'days' => 0,
            'calculation_base' => CalculationBase::ORDER_DATE,
            'sort_order' => 1,
        ]);

        $pi = $this->createPI('confirmed');
        $pi->update(['payment_term_id' => $term->id]);

        (new GeneratePaymentScheduleAction())->execute($pi);

        // Mark as paid before cancelling
        PaymentScheduleItem::where('payable_id', $pi->id)->update([
            'status' => PaymentScheduleStatus::PAID,
        ]);

        $this->action->execute($pi);

        $item = PaymentScheduleItem::where('payable_id', $pi->id)->first();
        $this->assertEquals(PaymentScheduleStatus::PAID, $item->status);
    }

    public function test_cancel_creates_state_transition_records(): void
    {
        $pi = $this->createPI('confirmed');
        $po = $this->createPOForPI($pi, 'draft');

        $this->action->execute($pi, 'Testing cancellation.');

        // PI transition
        $this->assertDatabaseHas('state_transitions', [
            'model_type' => ProformaInvoice::class,
            'model_id' => $pi->id,
            'from_status' => 'confirmed',
            'to_status' => 'cancelled',
        ]);

        // PO transition
        $this->assertDatabaseHas('state_transitions', [
            'model_type' => PurchaseOrder::class,
            'model_id' => $po->id,
            'from_status' => 'draft',
            'to_status' => 'cancelled',
        ]);
    }

    public function test_cancel_from_draft_status(): void
    {
        $pi = $this->createPI('draft');

        $result = $this->action->execute($pi);

        $this->assertEquals(ProformaInvoiceStatus::CANCELLED, $result->status);
    }

    public function test_cancel_from_sent_status(): void
    {
        $pi = $this->createPI('sent');

        $result = $this->action->execute($pi);

        $this->assertEquals(ProformaInvoiceStatus::CANCELLED, $result->status);
    }

    public function test_cannot_cancel_finalized_pi(): void
    {
        $pi = $this->createPI('finalized');

        $this->expectException(\InvalidArgumentException::class);
        $this->action->execute($pi);
    }
}
