<?php

namespace Tests\Feature\Livewire\SupplierPortal;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\SupplierPortal\ComponentInventoryPanel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComponentInventoryPanelTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplierCompany;
    private ProformaInvoice $pi;
    private ProformaInvoiceItem $piItem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $company = Company::create(['name' => 'Test Client', 'status' => 'active']);
        $company->companyRoles()->create(['role' => 'client']);

        $this->supplierCompany = Company::create(['name' => 'Test Supplier', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        $inquiry = Inquiry::create([
            'reference'     => 'INQ-CIP-001',
            'company_id'    => $company->id,
            'status'        => 'received',
            'source'        => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference'     => 'PI-CIP-001',
            'inquiry_id'    => $inquiry->id,
            'company_id'    => $company->id,
            'currency_code' => 'USD',
            'issue_date'    => '2026-03-01',
            'status'        => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description'         => 'Test Widget',
            'quantity'            => 100,
            'unit_price'          => 10_0000,
            'unit_cost'           => 5_0000,
        ]);
    }

    private function createSchedule(ProductionScheduleStatus $status = ProductionScheduleStatus::Draft): ProductionSchedule
    {
        return ProductionSchedule::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'reference'           => 'PS-CIP-' . mt_rand(1000, 9999),
            'received_date'       => '2026-03-10',
            'version'             => 1,
            'status'              => $status,
        ]);
    }

    public function test_saves_a_product_level_component_status(): void
    {
        $schedule = $this->createSchedule();

        Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
            ->call('saveComponent', $this->piItem->id, null, 'at_factory', null, null);

        $this->assertTrue(
            ProductionScheduleComponent::where([
                'production_schedule_id'   => $schedule->id,
                'proforma_invoice_item_id' => $this->piItem->id,
                'component_name'           => null,
                'status'                   => ComponentStatus::AtFactory->value,
            ])->exists()
        );
    }

    public function test_saves_a_named_sub_component(): void
    {
        $schedule = $this->createSchedule();

        Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
            ->call('saveComponent', $this->piItem->id, 'LCD Panel', 'in_transit', 'Supplier Corp', '2025-04-20');

        $this->assertTrue(
            ProductionScheduleComponent::where([
                'production_schedule_id'   => $schedule->id,
                'proforma_invoice_item_id' => $this->piItem->id,
                'component_name'           => 'LCD Panel',
                'status'                   => ComponentStatus::InTransit->value,
            ])->exists()
        );
    }

    public function test_deletes_a_component(): void
    {
        $schedule  = $this->createSchedule();
        $component = ProductionScheduleComponent::create([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'status'                   => ComponentStatus::AtSupplier,
        ]);

        Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
            ->call('deleteComponent', $component->id);

        $this->assertNull(ProductionScheduleComponent::find($component->id));
    }

    public function test_does_not_allow_editing_when_schedule_is_approved(): void
    {
        $schedule = $this->createSchedule(ProductionScheduleStatus::Approved);

        Livewire::test(ComponentInventoryPanel::class, ['schedule' => $schedule])
            ->call('saveComponent', $this->piItem->id, null, 'at_factory', null, null);

        $this->assertEquals(0, ProductionScheduleComponent::where('production_schedule_id', $schedule->id)->count());
    }
}
