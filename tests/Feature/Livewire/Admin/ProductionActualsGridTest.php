<?php

namespace Tests\Feature\Livewire\Admin;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Livewire\Admin\ProductionActualsGrid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionActualsGridTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Inquiry $inquiry;

    private ProformaInvoice $pi;

    private ProformaInvoiceItem $piItem;

    private ProductionSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->company = Company::create(['name' => 'Test Co', 'status' => 'active']);
        $this->company->companyRoles()->create(['role' => 'client']);

        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-ACT-001',
            'company_id' => $this->company->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-ACT-001',
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
        ]);

        $this->piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'description' => 'Widget A',
            'quantity' => 100,
            'unit_price' => 10_0000,
            'unit_cost' => 5_0000,
        ]);

        $this->schedule = ProductionSchedule::create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->company->id,
            'reference' => 'PS-ACT-001',
            'received_date' => '2026-03-10',
            'version' => 1,
            'status' => ProductionScheduleStatus::Approved,
        ]);
    }

    public function test_saves_actual_quantity_to_entry(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date' => '2025-04-10',
            'quantity' => 100,
            'actual_quantity' => null,
        ]);

        Livewire::test(ProductionActualsGrid::class, ['schedule' => $this->schedule])
            ->call('updateActual', $this->piItem->id, '2025-04-10', '95');

        $this->assertEquals(95, $entry->fresh()->actual_quantity);
    }

    public function test_marks_schedule_as_completed_when_all_actuals_meet_planned_quantities(): void
    {
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem->id,
            'production_date' => '2025-04-10',
            'quantity' => 100,
            'actual_quantity' => null,
        ]);

        Livewire::test(ProductionActualsGrid::class, ['schedule' => $this->schedule])
            ->call('updateActual', $this->piItem->id, '2025-04-10', '100')
            ->call('saveActuals');

        $this->assertEquals(ProductionScheduleStatus::Completed, $this->schedule->fresh()->status);
    }

    public function test_does_not_render_save_button_when_status_is_not_approved_or_completed(): void
    {
        $this->schedule->update(['status' => ProductionScheduleStatus::Draft]);

        Livewire::test(ProductionActualsGrid::class, ['schedule' => $this->schedule->fresh()])
            ->assertDontSee('Save Actuals');
    }
}
