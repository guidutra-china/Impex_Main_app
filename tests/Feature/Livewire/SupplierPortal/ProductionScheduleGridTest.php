<?php

namespace Tests\Feature\Livewire\SupplierPortal;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\SupplierPortal\ProductionScheduleGrid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionScheduleGridTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private ProformaInvoice $pi;
    private ProformaInvoiceItem $piItem;
    private ProformaInvoiceItem $piItem2;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->company = Company::create(['name' => 'Test Co', 'status' => 'active']);

        $inquiry = Inquiry::create([
            'reference'     => 'INQ-GRID-001',
            'company_id'    => $this->company->id,
            'status'        => 'received',
            'source'        => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference'     => 'PI-GRID-001',
            'inquiry_id'    => $inquiry->id,
            'company_id'    => $this->company->id,
            'currency_code' => 'USD',
            'issue_date'    => '2025-03-01',
            'status'        => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'description'         => 'Widget A',
            'quantity'            => 200,
            'unit_price'          => 10_00,
            'unit_cost'           => 5_00,
        ]);

        $this->piItem2 = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'description'         => 'Widget B',
            'quantity'            => 150,
            'unit_price'          => 20_00,
            'unit_cost'           => 10_00,
        ]);
    }

    private function makeSchedule(ProductionScheduleStatus $status = ProductionScheduleStatus::Draft): ProductionSchedule
    {
        return ProductionSchedule::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->company->id,
            'reference'           => 'PS-GRID-' . rand(1000, 9999),
            'version'             => 1,
            'status'              => $status,
        ]);
    }

    public function test_renders_items_and_dates_from_existing_entries(): void
    {
        $schedule = $this->makeSchedule();

        ProductionScheduleEntry::create([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date'          => '2025-04-10',
            'quantity'                 => 100,
        ]);

        Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
            ->assertSee('2025-04-10')
            ->assertSet('dates', ['2025-04-10']);
    }

    public function test_saves_entry_when_update_quantity_is_called(): void
    {
        $schedule = $this->makeSchedule();

        Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
            ->call('updateQuantity', $this->piItem->id, '2025-04-10', '150');

        $this->assertTrue(
            ProductionScheduleEntry::where([
                'production_schedule_id'   => $schedule->id,
                'proforma_invoice_item_id' => $this->piItem->id,
                'quantity'                 => 150,
            ])->whereDate('production_date', '2025-04-10')->exists()
        );
    }

    public function test_adds_a_new_date_column(): void
    {
        $schedule = $this->makeSchedule();

        Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
            ->set('newDateInput', '2025-04-17')
            ->call('addDate')
            ->assertSet('dates', ['2025-04-17']);
    }

    public function test_removes_a_date_and_deletes_its_entries(): void
    {
        $schedule = $this->makeSchedule();

        ProductionScheduleEntry::create([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date'          => '2025-04-10',
            'quantity'                 => 100,
        ]);

        Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
            ->call('removeDate', '2025-04-10')
            ->assertSet('dates', []);

        $this->assertDatabaseMissing('production_schedule_entries', [
            'production_schedule_id' => $schedule->id,
        ]);
    }

    public function test_transitions_to_pending_approval_on_submit_when_quantities_are_sufficient(): void
    {
        $schedule = $this->makeSchedule();

        // piItem requires 200, piItem2 requires 150
        ProductionScheduleEntry::create([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date'          => '2025-04-10',
            'quantity'                 => 200,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id'   => $schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date'          => '2025-04-10',
            'quantity'                 => 150,
        ]);

        Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
            ->call('submit');

        $this->assertEquals(ProductionScheduleStatus::PendingApproval, $schedule->fresh()->status);
    }

    public function test_does_not_allow_editing_when_status_is_not_draft_or_rejected(): void
    {
        $schedule = $this->makeSchedule(ProductionScheduleStatus::Approved);

        Livewire::test(ProductionScheduleGrid::class, ['schedule' => $schedule])
            ->call('updateQuantity', $this->piItem->id, '2025-04-10', '999');

        $this->assertDatabaseCount('production_schedule_entries', 0);
    }
}
