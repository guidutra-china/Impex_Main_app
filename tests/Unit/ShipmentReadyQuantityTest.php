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
 * Tests for shipment-ready quantity calculation and entry badge color logic.
 *
 * Covers:
 * - getShipmentReadyQuantityByItem grouped sums across multiple items and dates
 * - null actual_quantity is treated as 0 in sums (not "delayed")
 * - Entry badge color states: not-reported, on-track, partial, reported-zero
 */
class ShipmentReadyQuantityTest extends TestCase
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

        $this->clientCompany = Company::create(['name' => 'Client Co SRQ', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        $this->supplierCompany = Company::create(['name' => 'Supplier Co SRQ', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-SRQ-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->proformaInvoice = ProformaInvoice::create([
            'reference' => 'PI-SRQ-001',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->clientCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
        ]);

        $this->piItem1 = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Widget A',
            'quantity' => 200,
            'unit_price' => 10_0000,
            'unit_cost' => 5_0000,
        ]);

        $this->piItem2 = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'description' => 'Widget B',
            'quantity' => 100,
            'unit_price' => 20_0000,
            'unit_cost' => 10_0000,
        ]);

        $this->schedule = ProductionSchedule::create([
            'proforma_invoice_id' => $this->proformaInvoice->id,
            'supplier_company_id' => $this->supplierCompany->id,
            'reference' => 'PS-SRQ-001',
            'received_date' => '2026-03-10',
            'version' => 1,
        ]);
    }

    // === getShipmentReadyQuantityByItem — grouped sums ===

    public function test_shipment_ready_groups_by_pi_item_with_multiple_items_and_dates(): void
    {
        // Item 1: two entries on different dates
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
            'actual_quantity' => 45,
        ]);
        // Item 2: one entry
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date' => '2026-03-15',
            'quantity' => 30,
            'actual_quantity' => 28,
        ]);

        $this->schedule->load('entries');
        $result = $this->schedule->getShipmentReadyQuantityByItem();

        // Item1: 40 + 45 = 85
        $this->assertSame(85, $result->get($this->piItem1->id));
        // Item2: 28
        $this->assertSame(28, $result->get($this->piItem2->id));
        // Only 2 groups (one per item)
        $this->assertCount(2, $result);
    }

    public function test_shipment_ready_null_actual_quantity_contributes_zero_to_sum(): void
    {
        // null should be treated as 0 in sum, not as "delayed" — no exception
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => null,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-20',
            'quantity' => 50,
            'actual_quantity' => 30,
        ]);

        $this->schedule->load('entries');
        $result = $this->schedule->getShipmentReadyQuantityByItem();

        // null treated as 0 — sum is 0 + 30 = 30
        $this->assertSame(30, $result->get($this->piItem1->id));
    }

    public function test_shipment_ready_returns_empty_collection_when_no_entries(): void
    {
        $this->schedule->load('entries');
        $result = $this->schedule->getShipmentReadyQuantityByItem();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertCount(0, $result);
    }

    // === Badge color logic — mirrors EntriesRelationManager column logic ===

    /**
     * The badge color logic from EntriesRelationManager:
     *   null => 'gray'      (not reported)
     *   >= quantity => 'success'  (on or ahead)
     *   > 0 => 'warning'    (partial)
     *   default => 'danger' (reported zero)
     */
    private function resolveColor(?int $actual, int $planned): string
    {
        return match (true) {
            $actual === null => 'gray',
            $actual >= $planned => 'success',
            $actual > 0 => 'warning',
            default => 'danger',
        };
    }

    public function test_entry_null_actual_quantity_gets_gray_badge(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => null,
        ]);

        $color = $this->resolveColor($entry->actual_quantity, $entry->quantity);

        $this->assertSame('gray', $color, 'Not-reported (null) should get gray badge');
    }

    public function test_entry_actual_quantity_equal_to_planned_gets_success_badge(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 50,
        ]);

        $color = $this->resolveColor($entry->actual_quantity, $entry->quantity);

        $this->assertSame('success', $color, 'On track (actual == planned) should get success badge');
    }

    public function test_entry_actual_quantity_above_planned_gets_success_badge(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 60,
        ]);

        $color = $this->resolveColor($entry->actual_quantity, $entry->quantity);

        $this->assertSame('success', $color, 'Ahead (actual > planned) should get success badge');
    }

    public function test_entry_actual_quantity_partial_gets_warning_badge(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 25,
        ]);

        $color = $this->resolveColor($entry->actual_quantity, $entry->quantity);

        $this->assertSame('warning', $color, 'Partial (0 < actual < planned) should get warning badge');
    }

    public function test_entry_actual_quantity_zero_gets_danger_badge(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 0,
        ]);

        $color = $this->resolveColor($entry->actual_quantity, $entry->quantity);

        $this->assertSame('danger', $color, 'Reported zero should get danger badge');
    }

    public function test_entry_null_does_not_get_danger_badge(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => null,
        ]);

        // null is NOT the same as 0 — it should not get danger
        $color = $this->resolveColor($entry->actual_quantity, $entry->quantity);

        $this->assertNotSame('danger', $color, 'null should NOT get danger badge — only reported zero (0) should');
        $this->assertSame('gray', $color, 'null (not reported) must be gray, not danger');
    }
}
