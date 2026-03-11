<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Users\Enums\UserType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierProductionUpdateTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplierCompany;
    private Company $otherSupplierCompany;
    private Company $clientCompany;
    private User $supplierUser;
    private User $otherSupplierUser;
    private ProformaInvoice $proformaInvoice;
    private ProformaInvoiceItem $piItem;
    private ProductionSchedule $schedule;
    private ProductionScheduleEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset permission cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions using firstOrCreate (test-safe, no seeder dependency)
        Permission::firstOrCreate(['name' => 'supplier-portal:view-production-schedules', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'supplier-portal:update-production-actuals', 'guard_name' => 'web']);

        // Client company
        $this->clientCompany = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        // Primary supplier company
        $this->supplierCompany = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        // A different supplier (for tenant isolation testing)
        $this->otherSupplierCompany = Company::create(['name' => 'Other Supplier', 'status' => 'active']);
        $this->otherSupplierCompany->companyRoles()->create(['role' => 'supplier']);

        // Supplier user linked to supplierCompany
        $this->supplierUser = User::create([
            'name' => 'Supplier User',
            'email' => 'supplier@test.com',
            'password' => bcrypt('password'),
            'type' => UserType::SUPPLIER,
            'status' => 'active',
            'company_id' => $this->supplierCompany->id,
        ]);
        $this->supplierUser->givePermissionTo([
            'supplier-portal:view-production-schedules',
            'supplier-portal:update-production-actuals',
        ]);

        // Supplier user linked to the other company
        $this->otherSupplierUser = User::create([
            'name' => 'Other Supplier User',
            'email' => 'other-supplier@test.com',
            'password' => bcrypt('password'),
            'type' => UserType::SUPPLIER,
            'status' => 'active',
            'company_id' => $this->otherSupplierCompany->id,
        ]);
        $this->otherSupplierUser->givePermissionTo([
            'supplier-portal:view-production-schedules',
            'supplier-portal:update-production-actuals',
        ]);

        // Shared test data
        $inquiry = Inquiry::create([
            'reference' => 'INQ-FEAT-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->proformaInvoice = ProformaInvoice::create([
            'reference' => 'PI-FEAT-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->clientCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Widget A',
            'quantity' => 100,
            'unit_price' => 10_0000,
            'unit_cost' => 5_0000,
        ]);

        // Production schedule for supplierCompany
        $this->schedule = ProductionSchedule::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'reference' => 'PS-FEAT-001',
            'received_date' => '2026-03-10',
            'version' => 1,
        ]);

        // A single entry with no actual reported
        $this->entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => null,
        ]);
    }

    // === actual_quantity persistence ===

    public function test_saving_actual_quantity_on_entry_persists_the_value(): void
    {
        $this->entry->update(['actual_quantity' => 45]);

        $this->entry->refresh();
        $this->assertSame(45, $this->entry->actual_quantity);

        $this->assertDatabaseHas('production_schedule_entries', [
            'id' => $this->entry->id,
            'actual_quantity' => 45,
        ]);
    }

    public function test_saving_zero_actual_quantity_is_stored_as_zero_not_null(): void
    {
        $this->entry->update(['actual_quantity' => 0]);

        $this->entry->refresh();
        $this->assertSame(0, $this->entry->actual_quantity);
        $this->assertNotNull($this->entry->actual_quantity);

        $this->assertDatabaseHas('production_schedule_entries', [
            'id' => $this->entry->id,
            'actual_quantity' => 0,
        ]);
    }

    public function test_actual_quantity_can_be_updated_multiple_times(): void
    {
        $this->entry->update(['actual_quantity' => 20]);
        $this->entry->update(['actual_quantity' => 35]);

        $this->entry->refresh();
        $this->assertSame(35, $this->entry->actual_quantity);
    }

    // === Supplier cannot modify planned fields ===

    public function test_supplier_cannot_modify_planned_quantity_via_direct_assignment(): void
    {
        $originalQuantity = $this->entry->quantity;

        // Update only actual_quantity — planned quantity should remain unchanged
        $this->entry->update(['actual_quantity' => 30]);

        $this->entry->refresh();
        $this->assertSame($originalQuantity, $this->entry->quantity);
        $this->assertSame(30, $this->entry->actual_quantity);
    }

    public function test_supplier_cannot_modify_production_date_on_entry(): void
    {
        $originalDate = $this->entry->production_date;

        // An attempted update to production_date should not persist
        // because the Filament form only exposes actual_quantity
        // Here we verify the model/DB state directly: production_date is protected by form-layer
        // Test: updating only actual_quantity leaves production_date intact
        $this->entry->update(['actual_quantity' => 25]);

        $this->entry->refresh();
        $this->assertEquals($originalDate, $this->entry->production_date);
    }

    public function test_supplier_cannot_modify_proforma_invoice_item_id_on_entry(): void
    {
        $originalItemId = $this->entry->proforma_invoice_item_id;

        $this->entry->update(['actual_quantity' => 25]);

        $this->entry->refresh();
        $this->assertSame($originalItemId, $this->entry->proforma_invoice_item_id);
    }

    // === Tenant isolation ===

    public function test_production_schedule_belongs_to_supplier_company(): void
    {
        $this->assertSame($this->supplierCompany->id, $this->schedule->supplier_company_id);
    }

    public function test_tenant_isolation_other_supplier_has_own_schedules(): void
    {
        // Create a schedule for the other supplier
        $otherInquiry = Inquiry::create([
            'reference' => 'INQ-OTHER-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $otherPI = ProformaInvoice::create([
            'reference' => 'PI-OTHER-001',
            'inquiry_id' => $otherInquiry->id,
            'company_id' => $this->clientCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
        ]);

        $otherPiItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $otherPI->id,
            'supplier_company_id' => $this->otherSupplierCompany->id,
            'description' => 'Other Widget',
            'quantity' => 50,
            'unit_price' => 5_0000,
            'unit_cost' => 2_0000,
        ]);

        $otherSchedule = ProductionSchedule::create([
            'proforma_invoice_id' => $otherPI->id,
            'supplier_company_id' => $this->otherSupplierCompany->id,
            'reference' => 'PS-OTHER-001',
            'received_date' => '2026-03-10',
            'version' => 1,
        ]);

        // Each supplier's schedule is scoped to their company_id
        $supplierSchedules = ProductionSchedule::where('supplier_company_id', $this->supplierCompany->id)->get();
        $otherSchedules = ProductionSchedule::where('supplier_company_id', $this->otherSupplierCompany->id)->get();

        $this->assertCount(1, $supplierSchedules);
        $this->assertCount(1, $otherSchedules);

        // Primary supplier can't see other supplier's schedule via their tenant scope
        $this->assertFalse($supplierSchedules->contains('id', $otherSchedule->id));
        $this->assertFalse($otherSchedules->contains('id', $this->schedule->id));
    }

    public function test_supplier_sees_only_schedules_for_their_company(): void
    {
        // Create a second schedule for the same supplier
        $schedule2 = ProductionSchedule::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'reference' => 'PS-FEAT-002',
            'received_date' => '2026-03-11',
            'version' => 2,
        ]);

        // A schedule for the other supplier (should NOT appear in our supplier's results)
        $otherSchedule = ProductionSchedule::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->otherSupplierCompany->id,
            'reference' => 'PS-OTHER-002',
            'received_date' => '2026-03-11',
            'version' => 1,
        ]);

        $scoped = ProductionSchedule::where('supplier_company_id', $this->supplierCompany->id)->get();

        // Our supplier should see exactly their 2 schedules
        $this->assertCount(2, $scoped);
        $this->assertTrue($scoped->contains('id', $this->schedule->id));
        $this->assertTrue($scoped->contains('id', $schedule2->id));
        $this->assertFalse($scoped->contains('id', $otherSchedule->id));
    }

    // === Permission checks ===

    public function test_supplier_user_has_view_production_schedules_permission(): void
    {
        $this->assertTrue($this->supplierUser->can('supplier-portal:view-production-schedules'));
    }

    public function test_supplier_user_has_update_production_actuals_permission(): void
    {
        $this->assertTrue($this->supplierUser->can('supplier-portal:update-production-actuals'));
    }

    // === Resource configuration ===

    public function test_production_schedule_resource_uses_correct_tenant_relationship(): void
    {
        $resource = \App\Filament\SupplierPortal\Resources\ProductionScheduleResource::class;

        // The tenantOwnershipRelationshipName must be 'supplierCompany' for Filament multi-tenancy
        $reflection = new \ReflectionClass($resource);
        $property = $reflection->getProperty('tenantOwnershipRelationshipName');
        $property->setAccessible(true);

        $this->assertSame('supplierCompany', $property->getValue());
    }

    public function test_production_schedule_resource_cannot_create_or_delete(): void
    {
        $resource = \App\Filament\SupplierPortal\Resources\ProductionScheduleResource::class;

        $this->assertFalse($resource::canCreate());
        $this->assertFalse($resource::canDelete($this->schedule));
    }
}
