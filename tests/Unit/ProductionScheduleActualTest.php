<?php

namespace Tests\Unit;

use App\Domain\CRM\Models\Company;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Inquiries\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionScheduleActualTest extends TestCase
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

        $this->clientCompany = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => 'client']);

        $this->supplierCompany = Company::create(['name' => 'Supplier Co', 'status' => 'active']);
        $this->supplierCompany->companyRoles()->create(['role' => 'supplier']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-ACT-001',
            'company_id' => $this->clientCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->proformaInvoice = ProformaInvoice::create([
            'reference' => 'PI-ACT-001',
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
            'reference' => 'PS-ACT-001',
            'received_date' => '2026-03-10',
            'version' => 1,
        ]);
    }

    // === actual_quantity on entries ===

    public function test_entry_accepts_actual_quantity_as_nullable_integer(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => null,
        ]);

        $this->assertNull($entry->actual_quantity);
        $this->assertIsNotBool($entry->actual_quantity);
    }

    public function test_entry_actual_quantity_zero_is_valid_reported_value(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 0,
        ]);

        // 0 is a valid reported value — not null
        $this->assertNotNull($entry->actual_quantity);
        $this->assertSame(0, $entry->actual_quantity);
        $this->assertTrue($entry->actual_quantity !== null, 'Zero should be distinguishable from not-reported (null)');
    }

    public function test_entry_stores_positive_actual_quantity(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 45,
        ]);

        $entry->refresh();
        $this->assertSame(45, $entry->actual_quantity);
    }

    public function test_entry_actual_quantity_is_cast_to_integer(): void
    {
        $entry = ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 30,
        ]);

        $entry->refresh();
        $this->assertIsInt($entry->actual_quantity);
    }

    // === ProductionSchedule::getTotalActualQuantityAttribute ===

    public function test_total_actual_quantity_sums_all_entries_treating_null_as_zero(): void
    {
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 30,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-16',
            'quantity' => 50,
            'actual_quantity' => null, // not yet reported
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date' => '2026-03-15',
            'quantity' => 20,
            'actual_quantity' => 20,
        ]);

        $this->schedule->load('entries');
        $this->assertSame(50, $this->schedule->total_actual_quantity);
    }

    public function test_total_actual_quantity_is_zero_when_no_entries_reported(): void
    {
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => null,
        ]);

        $this->schedule->load('entries');
        $this->assertSame(0, $this->schedule->total_actual_quantity);
    }

    public function test_total_actual_quantity_counts_zero_reported_entries(): void
    {
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-15',
            'quantity' => 50,
            'actual_quantity' => 0,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date' => '2026-03-15',
            'quantity' => 20,
            'actual_quantity' => 15,
        ]);

        $this->schedule->load('entries');
        // 0 + 15 = 15
        $this->assertSame(15, $this->schedule->total_actual_quantity);
    }

    // === ProductionSchedule::getShipmentReadyQuantityByItem ===

    public function test_shipment_ready_quantity_by_item_returns_collection_keyed_by_pi_item_id(): void
    {
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
            'production_date' => '2026-03-16',
            'quantity' => 50,
            'actual_quantity' => 35,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem2->id,
            'production_date' => '2026-03-15',
            'quantity' => 20,
            'actual_quantity' => 18,
        ]);

        $this->schedule->load('entries');
        $result = $this->schedule->getShipmentReadyQuantityByItem();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
        $this->assertSame(75, $result->get($this->piItem1->id)); // 40 + 35
        $this->assertSame(18, $result->get($this->piItem2->id));
    }

    public function test_shipment_ready_quantity_treats_null_actual_as_zero(): void
    {
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
            'production_date' => '2026-03-16',
            'quantity' => 50,
            'actual_quantity' => 30,
        ]);

        $this->schedule->load('entries');
        $result = $this->schedule->getShipmentReadyQuantityByItem();

        $this->assertSame(30, $result->get($this->piItem1->id)); // null treated as 0, then + 30
    }

    // === Regression: getQuantityReadyByDate still works ===

    public function test_quantity_ready_by_date_regression_still_works(): void
    {
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-10',
            'quantity' => 30,
            'actual_quantity' => 25,
        ]);
        ProductionScheduleEntry::create([
            'production_schedule_id' => $this->schedule->id,
            'proforma_invoice_item_id' => $this->piItem1->id,
            'production_date' => '2026-03-20',
            'quantity' => 70,
            'actual_quantity' => 60,
        ]);

        $this->schedule->load('entries');

        // Before second entry date — only first entry
        $this->assertSame(30, $this->schedule->getQuantityReadyByDate(Carbon::parse('2026-03-15')));

        // After both entries — total planned
        $this->assertSame(100, $this->schedule->getQuantityReadyByDate(Carbon::parse('2026-03-25')));
    }
}
