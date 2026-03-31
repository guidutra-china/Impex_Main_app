<?php

namespace Tests\Feature\Livewire\SupplierPortal;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ComponentDelivery;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\SupplierPortal\ComponentDeliveryGrid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComponentDeliveryGridTest extends TestCase
{
    use RefreshDatabase;

    private ProductionSchedule $schedule;
    private ProductionScheduleComponent $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $company = Company::create(['name' => 'Test Co', 'status' => 'active']);

        $inquiry = Inquiry::create([
            'reference'     => 'INQ-CDG-001',
            'company_id'    => $company->id,
            'status'        => 'received',
            'source'        => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference'     => 'PI-CDG-001',
            'inquiry_id'    => $inquiry->id,
            'company_id'    => $company->id,
            'currency_code' => 'USD',
            'issue_date'    => '2025-03-01',
            'status'        => 'confirmed',
        ]);

        $item = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description'         => 'Widget A',
            'quantity'            => 100,
            'unit_price'          => 10_00,
            'unit_cost'           => 5_00,
        ]);

        $this->schedule = ProductionSchedule::create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $company->id,
            'reference'           => 'PS-CDG-001',
            'version'             => 1,
            'status'              => ProductionScheduleStatus::Draft,
        ]);

        $this->component = ProductionScheduleComponent::create([
            'production_schedule_id'   => $this->schedule->id,
            'proforma_invoice_item_id' => $item->id,
            'component_name'           => 'LCD Panel',
            'status'                   => ComponentStatus::AtSupplier,
            'supplier_name'            => 'Supplier X',
            'quantity_required'        => 100,
        ]);
    }

    public function test_renders_component_with_quantity_required(): void
    {
        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->assertSee('LCD Panel')
            ->assertSee('100');
    }

    public function test_adds_expected_delivery(): void
    {
        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('addDelivery', $this->component->id, '2025-04-10', 25);

        $this->assertDatabaseHas('component_deliveries', [
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-10 00:00:00',
            'expected_qty'                     => 25,
            'received_qty'                     => null,
        ]);
    }

    public function test_marks_delivery_as_received(): void
    {
        $delivery = ComponentDelivery::create([
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-10',
            'expected_qty'                     => 25,
        ]);

        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('markReceived', $delivery->id, 25);

        $delivery->refresh();
        $this->assertEquals(25, $delivery->received_qty);
        $this->assertNotNull($delivery->received_date);
    }

    public function test_removes_delivery(): void
    {
        $delivery = ComponentDelivery::create([
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-10',
            'expected_qty'                     => 25,
        ]);

        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('removeDelivery', $delivery->id);

        $this->assertDatabaseMissing('component_deliveries', ['id' => $delivery->id]);
    }

    public function test_does_not_allow_edits_when_approved(): void
    {
        $this->schedule->update(['status' => ProductionScheduleStatus::Approved]);

        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->call('addDelivery', $this->component->id, '2025-04-10', 25);

        $this->assertEquals(0, ComponentDelivery::count());
    }

    public function test_adds_date_column(): void
    {
        Livewire::test(ComponentDeliveryGrid::class, ['schedule' => $this->schedule])
            ->set('newDateInput', '2025-04-17')
            ->call('addDateColumn')
            ->assertSet('newDateInput', null);

        $this->assertDatabaseHas('component_deliveries', [
            'production_schedule_component_id' => $this->component->id,
            'expected_date'                    => '2025-04-17 00:00:00',
            'expected_qty'                     => 0,
        ]);
    }
}
