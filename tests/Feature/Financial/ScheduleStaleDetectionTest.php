<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HasPaymentSchedule::scheduleStaleness() — detecta schedules cujo total base
 * diverge dos valores atuais do documento (editado sem clicar em Regenerate).
 * A matemática esperada vem do ScheduleExpectationCalculator, o mesmo usado
 * pela regeneração, então "stale → Regenerate → ok" deve sempre fechar o ciclo.
 */
class ScheduleStaleDetectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Inquiry $inquiry;

    private GeneratePaymentScheduleAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(GeneratePaymentScheduleAction::class);

        $this->client = Company::create(['name' => 'Stale Test Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-STALE-001',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);
    }

    private function createTerm(array $stages, string $name = 'Stale Term'): PaymentTerm
    {
        $term = PaymentTerm::create(['name' => $name, 'is_active' => true]);

        foreach ($stages as $i => $stage) {
            PaymentTermStage::create(array_merge([
                'payment_term_id' => $term->id,
                'sort_order' => $i + 1,
            ], $stage));
        }

        return $term;
    }

    private function createPi(PaymentTerm $term, int $quantity = 100, int $unitPrice = 1000): ProformaInvoice
    {
        $pi = ProformaInvoice::create([
            'reference' => 'PI-STALE-'.uniqid(),
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
            'payment_term_id' => $term->id,
        ]);

        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'sort_order' => 1,
        ]);

        return $pi->fresh();
    }

    public function test_freshly_generated_pi_schedule_is_not_stale(): void
    {
        $term = $this->createTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);
        $pi = $this->createPi($term);

        $this->action->execute($pi);

        $this->assertSame('ok', $pi->fresh()->scheduleStaleness()['state']);
        $this->assertFalse($pi->fresh()->isScheduleStale());
    }

    public function test_editing_item_price_marks_pi_schedule_stale_with_correct_diff(): void
    {
        $term = $this->createTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);
        $pi = $this->createPi($term, quantity: 100, unitPrice: 1000); // total 100_000

        $this->action->execute($pi);

        // Value edited without regenerating: total 100_000 → 150_000.
        $pi->items()->first()->update(['unit_price' => 1500]);

        $staleness = $pi->fresh()->scheduleStaleness();

        $this->assertSame('stale', $staleness['state']);
        $this->assertSame(100_000, $staleness['actual']);
        $this->assertSame(150_000, $staleness['expected']);
        $this->assertSame(-50_000, $staleness['diff']);
    }

    public function test_regenerate_clears_staleness(): void
    {
        $term = $this->createTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);
        $pi = $this->createPi($term);

        $this->action->execute($pi);
        $pi->items()->first()->update(['unit_price' => 1500]);
        $this->assertTrue($pi->fresh()->isScheduleStale());

        $this->action->regenerate($pi->fresh());

        $this->assertFalse($pi->fresh()->isScheduleStale());
    }

    public function test_changing_payment_term_marks_schedule_stale(): void
    {
        $term = $this->createTerm([
            ['percentage' => 100, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
        ]);
        $pi = $this->createPi($term);

        $this->action->execute($pi);

        // Same 100% split, different stages: the amount check alone would
        // pass — the stage-set check must flag it.
        $newTerm = $this->createTerm([
            ['percentage' => 50, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 50, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ], 'Swapped Term');
        $pi->update(['payment_term_id' => $newTerm->id]);

        $this->assertTrue($pi->fresh()->isScheduleStale());

        $this->action->regenerate($pi->fresh());

        $this->assertFalse($pi->fresh()->isScheduleStale());
    }

    public function test_overridden_item_reports_overridden_instead_of_stale(): void
    {
        $term = $this->createTerm([
            ['percentage' => 100, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
        ]);
        $pi = $this->createPi($term);

        $this->action->execute($pi);

        $pi->paymentScheduleItems()->first()->update([
            'amount' => 12_345,
            'overridden_at' => now(),
            'override_reason' => 'negotiated',
        ]);

        $fresh = $pi->fresh();
        $this->assertSame('overridden', $fresh->scheduleStaleness()['state']);
        $this->assertFalse($fresh->isScheduleStale());
    }

    public function test_plan_items_without_shipment_report_plan_state(): void
    {
        $term = $this->createTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);
        $pi = $this->createPi($term);

        $this->action->execute($pi);

        $plan = ShipmentPlan::create([
            'supplier_company_id' => $this->client->id,
            'reference' => 'SP-STALE-001',
            'status' => 'confirmed',
            'currency_code' => 'USD',
        ]);

        // Fatia do stage 70% gerida pelo fluxo de Shipment Plans.
        $pi->paymentScheduleItems()
            ->where('percentage', 70)
            ->first()
            ->update(['shipment_plan_id' => $plan->id]);

        $fresh = $pi->fresh();
        $this->assertSame('plan', $fresh->scheduleStaleness()['state']);
        $this->assertFalse($fresh->isScheduleStale());
    }

    public function test_editing_po_item_cost_marks_po_schedule_stale(): void
    {
        $term = $this->createTerm([
            ['percentage' => 100, 'days' => 0, 'calculation_base' => CalculationBase::PO_DATE],
        ]);

        $supplier = Company::create(['name' => 'Stale Supplier', 'status' => 'active']);
        $supplier->companyRoles()->create(['role' => 'supplier']);

        $po = PurchaseOrder::factory()->create([
            'supplier_company_id' => $supplier->id,
            'payment_term_id' => $term->id,
            'status' => 'confirmed',
            'currency_code' => 'USD',
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'description' => 'Widget',
            'quantity' => 100,
            'unit_cost' => 800,
            'sort_order' => 1,
        ]);

        $this->action->execute($po->fresh());
        $this->assertFalse($po->fresh()->isScheduleStale());

        $po->items()->first()->update(['unit_cost' => 900]);

        $staleness = $po->fresh()->scheduleStaleness();
        $this->assertSame('stale', $staleness['state']);
        $this->assertSame(80_000, $staleness['actual']);
        $this->assertSame(90_000, $staleness['expected']);
    }

    public function test_changing_shipped_quantity_marks_shipment_schedule_stale(): void
    {
        $term = $this->createTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);
        $pi = $this->createPi($term, quantity: 100, unitPrice: 1000);

        $this->action->execute($pi);

        $shipment = Shipment::create([
            'reference' => 'SHP-STALE-001',
            'company_id' => $this->client->id,
            'issue_date' => '2026-03-20',
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => 'USD',
        ]);
        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $pi->items()->first()->id,
            'quantity' => 60,
            'total_weight' => 60,
            'total_volume' => 0,
            'sort_order' => 1,
        ]);

        $this->action->executeForShipment($shipment->fresh());
        $this->assertFalse($shipment->fresh()->isScheduleStale(), 'freshly generated shipment schedule must not be stale');

        // Shipped quantity edited without regenerating: 60 → 80 units.
        $shipmentItem->update(['quantity' => 80]);

        $staleness = $shipment->fresh()->scheduleStaleness();
        $this->assertSame('stale', $staleness['state']);
        $this->assertSame((int) round(60_000 * 0.7), $staleness['actual']);
        $this->assertSame((int) round(80_000 * 0.7), $staleness['expected']);

        $this->action->regenerateForShipment($shipment->fresh());
        $this->assertFalse($shipment->fresh()->isScheduleStale());
    }

    public function test_base_total_ignores_additional_cost_and_credit_items(): void
    {
        $term = $this->createTerm([
            ['percentage' => 100, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
        ]);
        $pi = $this->createPi($term);

        $this->action->execute($pi);

        // Item extra sem payment_term_stage_id (ex.: custo adicional) não pode
        // entrar na comparação do total base.
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'label' => 'Freight: sea freight',
            'percentage' => 0,
            'amount' => 5_000,
            'currency_code' => 'USD',
            'status' => \App\Domain\Financial\Enums\PaymentScheduleStatus::DUE,
            'sort_order' => 99,
        ]);

        $this->assertFalse($pi->fresh()->isScheduleStale());
    }
}
