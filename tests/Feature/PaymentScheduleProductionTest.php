<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Planning\Actions\UpdatePaymentScheduleFromProductionAction;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentScheduleProductionTest extends TestCase
{
    use RefreshDatabase;

    private Company $clientCompany;
    private Company $supplierCompany;
    private Inquiry $inquiry;
    private ProformaInvoice $pi;
    private ProformaInvoiceItem $piItem;
    private ProductionSchedule $schedule;
    private UpdatePaymentScheduleFromProductionAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new UpdatePaymentScheduleFromProductionAction();

        $this->clientCompany = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        $this->supplierCompany = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-PROD-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-PROD-001',
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->clientCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Widget A',
            'quantity' => 100,
            'unit_price' => 10_0000,
            'unit_cost' => 5_0000,
        ]);

        $this->schedule = ProductionSchedule::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'reference' => 'PS-PROD-001',
            'received_date' => '2026-03-10',
            'version' => 1,
        ]);
    }

    private function createPaymentItem(int $percentage, ?string $status = null, ?string $dueDate = null): PaymentScheduleItem
    {
        return PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => "After Production {$percentage}%",
            'percentage' => $percentage,
            'amount' => 100_0000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::AFTER_PRODUCTION,
            'due_date' => $dueDate,
            'status' => $status ?? PaymentScheduleStatus::PENDING->value,
        ]);
    }

    private function createEntry(int $quantity, ?int $actualQuantity = null): ProductionScheduleEntry
    {
        static $counter = 0;
        $counter++;

        return ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date' => "2026-03-{$counter}",
            'quantity' => $quantity,
            'actual_quantity' => $actualQuantity,
        ]);
    }

    // ===  Tests ===

    public function test_due_date_is_set_when_readiness_reaches_threshold(): void
    {
        // PI has 100 units planned (piItem.quantity = 100)
        // Create entries with 50 actual units produced -> 50% readiness
        $this->createEntry(50, 50);

        // Payment item at 50% threshold -> should be triggered
        $paymentItem = $this->createPaymentItem(50);

        $result = $this->action->execute($this->schedule);

        $this->assertContains($paymentItem->id, $result);

        $paymentItem->refresh();
        $this->assertNotNull($paymentItem->due_date);
        $this->assertEquals(now()->toDateString(), $paymentItem->due_date->toDateString());
    }

    public function test_due_date_not_set_when_threshold_not_crossed(): void
    {
        // 40 of 100 produced = 40% readiness
        $this->createEntry(100, 40);

        // Payment item at 50% threshold -> should NOT be triggered
        $paymentItem = $this->createPaymentItem(50);

        $result = $this->action->execute($this->schedule);

        $this->assertEmpty($result);

        $paymentItem->refresh();
        $this->assertNull($paymentItem->due_date);
    }

    public function test_item_with_existing_due_date_is_not_re_updated_idempotent(): void
    {
        // 80 of 100 produced = 80% readiness
        $this->createEntry(100, 80);

        // Payment item already has a due_date set
        $existingDate = '2026-01-01';
        $paymentItem = $this->createPaymentItem(50, PaymentScheduleStatus::PENDING->value, $existingDate);

        $result = $this->action->execute($this->schedule);

        // Should not be in the updated list (was skipped due to existing due_date)
        $this->assertNotContains($paymentItem->id, $result);

        $paymentItem->refresh();
        // Due date should remain the original value (not changed to today)
        $this->assertEquals($existingDate, $paymentItem->due_date->toDateString());
    }

    public function test_only_pending_items_with_after_production_condition_are_affected(): void
    {
        // 100% readiness
        $this->createEntry(100, 100);

        // AFTER_PRODUCTION + PENDING -> should be updated
        $pendingItem = $this->createPaymentItem(50);

        // Non-AFTER_PRODUCTION item -> should NOT be updated
        $nonAfterProductionItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'Order Date Item',
            'percentage' => 30,
            'amount' => 50_0000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::ORDER_DATE,
            'due_date' => null,
            'status' => PaymentScheduleStatus::PENDING->value,
        ]);

        $result = $this->action->execute($this->schedule);

        $this->assertContains($pendingItem->id, $result);
        $this->assertNotContains($nonAfterProductionItem->id, $result);

        $nonAfterProductionItem->refresh();
        $this->assertNull($nonAfterProductionItem->due_date);
    }

    public function test_paid_items_are_skipped(): void
    {
        // 100% readiness
        $this->createEntry(100, 100);

        $paidItem = $this->createPaymentItem(50, PaymentScheduleStatus::PAID->value);

        $result = $this->action->execute($this->schedule);

        $this->assertNotContains($paidItem->id, $result);
    }

    public function test_waived_items_are_skipped(): void
    {
        // 100% readiness
        $this->createEntry(100, 100);

        $waivedItem = $this->createPaymentItem(50, PaymentScheduleStatus::WAIVED->value);

        $result = $this->action->execute($this->schedule);

        $this->assertNotContains($waivedItem->id, $result);
    }

    public function test_action_returns_array_of_updated_item_ids(): void
    {
        // 100% readiness
        $this->createEntry(100, 100);

        $item1 = $this->createPaymentItem(30);
        $item2 = $this->createPaymentItem(50);
        $item3 = $this->createPaymentItem(80);

        $result = $this->action->execute($this->schedule);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains($item1->id, $result);
        $this->assertContains($item2->id, $result);
        $this->assertContains($item3->id, $result);
    }

    public function test_zero_total_planned_does_not_cause_division_by_zero(): void
    {
        // PI item with quantity = 0 (edge case)
        $zeroQtyItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Zero Qty Item',
            'quantity' => 0,
            'unit_price' => 0,
            'unit_cost' => 0,
        ]);

        // Update the existing piItem to have zero quantity
        $this->piItem->update(['quantity' => 0]);

        $paymentItem = $this->createPaymentItem(50);

        // Should not throw — action should return empty result gracefully
        $result = $this->action->execute($this->schedule);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_overall_pi_readiness_uses_sum_across_all_entries(): void
    {
        // PI item has 100 units
        // Two production entries: 30 produced + 25 produced = 55 total actual
        $this->createEntry(50, 30);
        $this->createEntry(50, 25);

        // 55/100 = 55% readiness
        $item50 = $this->createPaymentItem(50); // 55% >= 50% -> should trigger
        $item60 = $this->createPaymentItem(60); // 55% < 60% -> should NOT trigger

        $result = $this->action->execute($this->schedule);

        $this->assertContains($item50->id, $result);
        $this->assertNotContains($item60->id, $result);
    }
}
