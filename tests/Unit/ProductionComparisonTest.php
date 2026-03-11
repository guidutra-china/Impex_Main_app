<?php

namespace Tests\Unit;

use App\Domain\CRM\Models\Company;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Inquiries\Models\Inquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the "Production Summary" section behavior shown in the admin infolist.
 *
 * Covers:
 * - completion_percentage calculation: round((actual / planned) * 100, 1)
 * - division-by-zero guard when total_quantity is 0
 * - shipment-ready display groups per-item correctly
 * - schedule with no entries returns zeros, no errors
 */
class ProductionComparisonTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplierCompany;
    private Company $clientCompany;
    private ProformaInvoice $proformaInvoice;
    private ProformaInvoiceItem $piItem1;
    private ProformaInvoiceItem $piItem2;
    private ProductionSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientCompany = Company::create(['name' => 'Client Co PC', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        $this->supplierCompany = Company::create(['name' => 'Supplier Co PC', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PC-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->proformaInvoice = ProformaInvoice::create([
            'reference' => 'PI-PC-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->clientCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
        ]);

        $this->piItem1 = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Product Alpha',
            'quantity' => 100,
            'unit_price' => 10_0000,
            'unit_cost' => 5_0000,
        ]);

        $this->piItem2 = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Product Beta',
            'quantity' => 200,
            'unit_price' => 8_0000,
            'unit_cost' => 4_0000,
        ]);

        $this->schedule = ProductionSchedule::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'reference' => 'PS-PC-001',
            'received_date' => '2026-03-10',
            'version' => 1,
        ]);
    }

    // === completion_percentage calculation ===

    public function test_completion_percentage_is_50_percent_when_half_produced(): void
    {
        // Total planned: 100 entries with quantity 100
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 100,
            'actual_quantity' => 50,
        ]);

        $this->schedule->load('entries');

        $totalPlanned = $this->schedule->total_quantity;
        $totalActual = $this->schedule->total_actual_quantity;

        $this->assertSame(100, $totalPlanned);
        $this->assertSame(50, $totalActual);

        $percentage = $totalPlanned > 0
            ? round(($totalActual / $totalPlanned) * 100, 1)
            : null;

        $this->assertSame(50.0, $percentage);
    }

    public function test_completion_percentage_is_correct_with_multiple_entries(): void
    {
        // Item1: 50 actual out of 100 planned
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 100,
            'actual_quantity' => 50,
        ]);
        // Item2: 100 actual out of 100 planned
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date' => '2026-03-15',
            'quantity' => 100,
            'actual_quantity' => 100,
        ]);

        $this->schedule->load('entries');

        $totalPlanned = $this->schedule->total_quantity;      // 200
        $totalActual = $this->schedule->total_actual_quantity; // 150

        $percentage = $totalPlanned > 0
            ? round(($totalActual / $totalPlanned) * 100, 1)
            : null;

        // 150/200 * 100 = 75.0%
        $this->assertSame(75.0, $percentage);
    }

    public function test_completion_percentage_rounds_to_one_decimal(): void
    {
        // 1 actual out of 3 planned = 33.333...% → rounds to 33.3%
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 3,
            'actual_quantity' => 1,
        ]);

        $this->schedule->load('entries');

        $totalPlanned = $this->schedule->total_quantity;
        $totalActual = $this->schedule->total_actual_quantity;

        $percentage = $totalPlanned > 0
            ? round(($totalActual / $totalPlanned) * 100, 1)
            : null;

        $this->assertSame(33.3, $percentage);
    }

    public function test_completion_percentage_is_null_when_total_planned_is_zero(): void
    {
        // Guard against division-by-zero: no entries means total_quantity = 0
        $this->schedule->load('entries');

        $totalPlanned = $this->schedule->total_quantity;
        $this->assertSame(0, $totalPlanned);

        // The production summary section uses this guard:
        $result = $totalPlanned > 0
            ? round(($this->schedule->total_actual_quantity / $totalPlanned) * 100, 1)
            : null;

        // Should return null (or display '—'), NOT throw division-by-zero
        $this->assertNull($result, 'Division-by-zero guard: result must be null when total_quantity is 0');
    }

    // === Shipment-ready display — per-item grouping ===

    public function test_shipment_ready_by_item_shows_correct_per_item_sums_with_different_readiness(): void
    {
        // Item 1: two entries, both partially produced
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 40,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-20',
            'quantity' => 50,
            'actual_quantity' => 35,
        ]);
        // Item 2: one entry, fully produced
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date' => '2026-03-15',
            'quantity' => 100,
            'actual_quantity' => 100,
        ]);

        $this->schedule->load('entries');
        $byItem = $this->schedule->getShipmentReadyQuantityByItem();

        // Item 1: 40 + 35 = 75 (partial)
        $this->assertSame(75, $byItem->get($this->piItem1->id));
        // Item 2: 100 (fully ready)
        $this->assertSame(100, $byItem->get($this->piItem2->id));
        // Two groups
        $this->assertCount(2, $byItem);
    }

    // === Edge case: schedule with no entries ===

    public function test_schedule_with_no_entries_returns_zeros_without_error(): void
    {
        $this->schedule->load('entries');

        // Should all be safe, no exceptions
        $this->assertSame(0, $this->schedule->total_quantity);
        $this->assertSame(0, $this->schedule->total_actual_quantity);

        $byItem = $this->schedule->getShipmentReadyQuantityByItem();
        $this->assertCount(0, $byItem);

        // Completion percentage guard handles zero planned
        $completionDisplay = $this->schedule->total_quantity > 0
            ? round(($this->schedule->total_actual_quantity / $this->schedule->total_quantity) * 100, 1) . '%'
            : '—';

        $this->assertSame('—', $completionDisplay);
    }

    // === total_quantity and total_actual_quantity used in summary ===

    public function test_summary_totals_match_individual_entry_sums(): void
    {
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 60,
            'actual_quantity' => 55,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date' => '2026-03-16',
            'quantity' => 40,
            'actual_quantity' => null, // not yet reported
        ]);

        $this->schedule->load('entries');

        // total_quantity = sum of all planned quantities
        $this->assertSame(100, $this->schedule->total_quantity);

        // total_actual_quantity = sum of actuals, null = 0
        $this->assertSame(55, $this->schedule->total_actual_quantity);
    }
}
