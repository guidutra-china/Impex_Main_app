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
use App\Domain\Users\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierPortalPaymentWiringTest extends TestCase
{
    use RefreshDatabase;

    private Company $clientCompany;
    private Company $supplierCompany;
    private User $supplierUser;
    private ProformaInvoice $pi;
    private ProformaInvoiceItem $piItem;
    private ProductionSchedule $schedule;
    private UpdatePaymentScheduleFromProductionAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'supplier-portal:view-production-schedules', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'supplier-portal:update-production-actuals', 'guard_name' => 'web']);

        $this->action = new UpdatePaymentScheduleFromProductionAction();

        $this->clientCompany = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        $this->supplierCompany = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        $this->supplierUser = User::create([
            'name' => 'Supplier User',
            'email' => 'supplier@wiring-test.com',
            'password' => bcrypt('password'),
            'type' => UserType::SUPPLIER,
            'status' => 'active',
            'company_id' => $this->supplierCompany->id,
        ]);
        $this->supplierUser->givePermissionTo([
            'supplier-portal:view-production-schedules',
            'supplier-portal:update-production-actuals',
        ]);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-WIRE-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-WIRE-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->clientCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Widget B',
            'quantity' => 100,
            'unit_price' => 10_0000,
            'unit_cost' => 5_0000,
        ]);

        $this->schedule = ProductionSchedule::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'reference' => 'PS-WIRE-001',
            'received_date' => '2026-03-10',
            'version' => 1,
        ]);
    }

    /**
     * Test: Saving actual_quantity via the action triggers payment schedule update
     * when the production readiness crosses the payment item threshold.
     *
     * Note: We test the action directly (simulating the portal save callback) since
     * testing Filament EditAction::after() wiring in a unit test is not practical.
     * The wiring itself is verified by code inspection of EntriesRelationManager.
     */
    public function test_saving_actual_quantity_triggers_payment_schedule_update_when_threshold_crossed(): void
    {
        // Set up: 50 actual out of 100 planned = 50% readiness
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date' => '2026-03-15',
            'quantity' => 100,
            'actual_quantity' => 50,
        ]);

        $paymentItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'After Production 50%',
            'percentage' => 50,
            'amount' => 100_0000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::AFTER_PRODUCTION,
            'due_date' => null,
            'status' => PaymentScheduleStatus::PENDING->value,
        ]);

        // Simulate what happens after entry.actual_quantity is saved in the portal:
        // EntriesRelationManager calls UpdatePaymentScheduleFromProductionAction::execute()
        $this->actingAs($this->supplierUser);
        $updatedIds = $this->action->execute($this->schedule);

        $this->assertContains($paymentItem->id, $updatedIds);

        $paymentItem->refresh();
        $this->assertNotNull($paymentItem->due_date);
        $this->assertEquals(now()->toDateString(), $paymentItem->due_date->toDateString());
    }

    public function test_payment_item_remains_null_when_threshold_not_crossed_after_save(): void
    {
        // 40 of 100 produced = 40% readiness
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date' => '2026-03-15',
            'quantity' => 100,
            'actual_quantity' => 40,
        ]);

        $paymentItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'After Production 50%',
            'percentage' => 50,
            'amount' => 100_0000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::AFTER_PRODUCTION,
            'due_date' => null,
            'status' => PaymentScheduleStatus::PENDING->value,
        ]);

        $this->actingAs($this->supplierUser);
        $updatedIds = $this->action->execute($this->schedule);

        $this->assertEmpty($updatedIds);

        $paymentItem->refresh();
        $this->assertNull($paymentItem->due_date);
    }

    public function test_non_qualifying_payment_items_remain_unchanged_after_save(): void
    {
        // 100% readiness
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date' => '2026-03-15',
            'quantity' => 100,
            'actual_quantity' => 100,
        ]);

        // AFTER_PRODUCTION + PENDING -> should be updated
        $qualifyingItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'After Production 50%',
            'percentage' => 50,
            'amount' => 100_0000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::AFTER_PRODUCTION,
            'due_date' => null,
            'status' => PaymentScheduleStatus::PENDING->value,
        ]);

        // Non-AFTER_PRODUCTION item -> should NOT be updated
        $nonQualifyingItem = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $this->pi->id,
            'label' => 'Before Shipment',
            'percentage' => 30,
            'amount' => 50_0000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
            'due_date' => null,
            'status' => PaymentScheduleStatus::PENDING->value,
        ]);

        $this->actingAs($this->supplierUser);
        $updatedIds = $this->action->execute($this->schedule);

        $this->assertContains($qualifyingItem->id, $updatedIds);
        $this->assertNotContains($nonQualifyingItem->id, $updatedIds);

        $nonQualifyingItem->refresh();
        $this->assertNull($nonQualifyingItem->due_date);
    }

    /**
     * Verify the EntriesRelationManager has the EditAction::after() wiring.
     * This is a structural test — confirms code is in place to call the action.
     */
    public function test_entries_relation_manager_has_after_callback_wiring(): void
    {
        $source = file_get_contents(
            base_path('app/Filament/SupplierPortal/Resources/ProductionScheduleResource/RelationManagers/EntriesRelationManager.php')
        );

        $this->assertStringContainsString('UpdatePaymentScheduleFromProductionAction', $source,
            'EntriesRelationManager should import UpdatePaymentScheduleFromProductionAction');

        $this->assertStringContainsString('->after(', $source,
            'EntriesRelationManager EditAction should have an after() callback');
    }
}
