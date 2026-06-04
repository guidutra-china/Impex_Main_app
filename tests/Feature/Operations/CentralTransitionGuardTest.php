<?php

namespace Tests\Feature\Operations;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Exceptions\TransitionBlockedException;
use App\Domain\Planning\Enums\ShipmentPlanStatus;
use App\Domain\Planning\Models\ShipmentPlan;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Enums\PurchaseOrderStatus;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralTransitionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_throws_when_proforma_invoice_finalization_is_blocked(): void
    {
        $pi = ProformaInvoice::factory()->create([
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);
        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 10,
            'unit_price' => 100,
            'sort_order' => 0,
        ]);

        $this->assertNotEmpty($pi->getFinalizationBlockers());

        try {
            app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::FINALIZED);
            $this->fail('Expected TransitionBlockedException was not thrown.');
        } catch (TransitionBlockedException $e) {
            $this->assertNotEmpty($e->blockers);
        }

        $this->assertSame(
            ProformaInvoiceStatus::CONFIRMED->value,
            $pi->fresh()->status->value,
            'PI status must be unchanged when blocked.'
        );
    }

    public function test_execute_succeeds_when_proforma_invoice_has_no_blockers(): void
    {
        $pi = ProformaInvoice::factory()->create([
            'status' => ProformaInvoiceStatus::CONFIRMED->value,
        ]);

        $this->assertEmpty($pi->getFinalizationBlockers());

        app(TransitionStatusAction::class)->execute($pi, ProformaInvoiceStatus::FINALIZED);

        $this->assertSame(
            ProformaInvoiceStatus::FINALIZED->value,
            $pi->fresh()->status->value,
        );
    }

    public function test_execute_throws_when_purchase_order_has_blocking_payment(): void
    {
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::CONFIRMED->value,
        ]);

        PaymentScheduleItem::factory()->create([
            'payable_type' => $po->getMorphClass(),
            'payable_id' => $po->getKey(),
            'is_blocking' => true,
            'is_credit' => false,
            'status' => PaymentScheduleStatus::DUE->value,
            'due_condition' => CalculationBase::BEFORE_PRODUCTION->value,
        ]);

        $this->assertNotEmpty($po->getBlockingPaymentLabels(PurchaseOrderStatus::IN_PRODUCTION->value));

        try {
            app(TransitionStatusAction::class)->execute($po, PurchaseOrderStatus::IN_PRODUCTION);
            $this->fail('Expected TransitionBlockedException was not thrown.');
        } catch (TransitionBlockedException $e) {
            $this->assertNotEmpty($e->blockers);
        }

        $this->assertSame(
            PurchaseOrderStatus::CONFIRMED->value,
            $po->fresh()->status->value,
        );
    }

    public function test_execute_succeeds_when_purchase_order_has_no_blocking_payment(): void
    {
        // No PaymentScheduleItem => the unconditional getBlockingPaymentLabels() override returns [].
        $po = PurchaseOrder::factory()->create([
            'status' => PurchaseOrderStatus::CONFIRMED->value,
        ]);

        $this->assertEmpty($po->getBlockingPaymentLabels(PurchaseOrderStatus::IN_PRODUCTION->value));

        app(TransitionStatusAction::class)->execute($po, PurchaseOrderStatus::IN_PRODUCTION);

        $this->assertSame(
            PurchaseOrderStatus::IN_PRODUCTION->value,
            $po->fresh()->status->value,
        );
    }

    public function test_execute_throws_when_shipment_plan_has_blocking_payment(): void
    {
        $plan = ShipmentPlan::factory()->create([
            'status' => ShipmentPlanStatus::CONFIRMED->value,
        ]);

        // ShipmentPlan resolves blockers via shipment_plan_id (linkedPaymentScheduleItems),
        // NOT the polymorphic payable — which, as in production (ConfirmShipmentPlanAction),
        // points at the ProformaInvoice (the factory default). shipment_plan_id is what links it.
        PaymentScheduleItem::factory()->create([
            'shipment_plan_id' => $plan->getKey(),
            'is_blocking' => true,
            'is_credit' => false,
            'status' => PaymentScheduleStatus::DUE->value,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT->value,
        ]);

        $this->assertTrue($plan->hasBlockingPayments());

        try {
            app(TransitionStatusAction::class)->execute($plan, ShipmentPlanStatus::SHIPPED);
            $this->fail('Expected TransitionBlockedException was not thrown.');
        } catch (TransitionBlockedException $e) {
            $this->assertNotEmpty($e->blockers);
        }

        $this->assertSame(
            ShipmentPlanStatus::CONFIRMED->value,
            $plan->fresh()->status->value,
        );
    }
}
