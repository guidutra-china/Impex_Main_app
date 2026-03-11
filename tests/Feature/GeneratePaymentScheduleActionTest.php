<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\GeneratePaymentScheduleAction;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeneratePaymentScheduleActionTest extends TestCase
{
    use RefreshDatabase;

    private GeneratePaymentScheduleAction $action;
    private Company $company;
    private Inquiry $inquiry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = new GeneratePaymentScheduleAction();

        $this->company = Company::create([
            'name' => 'Test Company',
            'status' => 'active',
        ]);
        $this->company->companyRoles()->create(['role' => 'client']);

        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-TEST-001',
            'company_id' => $this->company->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);
    }

    private function createProformaInvoice(array $overrides = []): ProformaInvoice
    {
        $pi = ProformaInvoice::create(array_merge([
            'reference' => 'PI-TEST-' . uniqid(),
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->company->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'draft',
        ], $overrides));

        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Test Product',
            'quantity' => 100,
            'unit_price' => 100_0000,
        ]);

        $pi->load('items');

        return $pi;
    }

    private function createPaymentTerm(array $stages): PaymentTerm
    {
        $term = PaymentTerm::create([
            'name' => 'Test Term',
            'is_active' => true,
        ]);

        foreach ($stages as $i => $stage) {
            PaymentTermStage::create(array_merge([
                'payment_term_id' => $term->id,
                'sort_order' => $i + 1,
            ], $stage));
        }

        return $term;
    }

    // === execute() tests ===

    public function test_execute_creates_schedule_items_for_all_stages(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 30, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);

        $count = $this->action->execute($pi);

        $this->assertEquals(2, $count);

        $items = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(2, $items);
    }

    public function test_execute_creates_shipment_dependent_items_with_null_due_date(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 30, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);
        $this->action->execute($pi);

        $items = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->orderBy('sort_order')
            ->get();

        // Order date stage should have a due date
        $this->assertNotNull($items[0]->due_date);

        // Shipment-dependent stage should have null due date (TBD)
        $this->assertNull($items[1]->due_date);
    }

    public function test_execute_calculates_correct_amounts(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);
        $this->action->execute($pi);

        $items = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->orderBy('sort_order')
            ->get();

        $total = $pi->total;
        $this->assertEquals((int) round($total * 0.3), $items[0]->amount);
        $this->assertEquals((int) round($total * 0.7), $items[1]->amount);
    }

    public function test_execute_does_not_duplicate_if_items_exist(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 100, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);

        $this->action->execute($pi);
        $secondRun = $this->action->execute($pi);

        $this->assertEquals(0, $secondRun);
        $this->assertEquals(1, PaymentScheduleItem::where('payable_id', $pi->id)->count());
    }

    public function test_execute_returns_zero_without_payment_term(): void
    {
        $pi = $this->createProformaInvoice();
        $count = $this->action->execute($pi);

        $this->assertEquals(0, $count);
    }

    public function test_execute_sets_blocking_for_pre_conditions(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_PRODUCTION],
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_SHIPMENT],
            ['percentage' => 40, 'days' => 30, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);
        $this->action->execute($pi);

        $items = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertTrue($items[0]->is_blocking);  // BEFORE_PRODUCTION
        $this->assertTrue($items[1]->is_blocking);  // BEFORE_SHIPMENT
        $this->assertFalse($items[2]->is_blocking); // SHIPMENT_DATE (not blocking)
    }

    public function test_execute_creates_all_shipment_dependent_types_with_null_dates(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 25, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
            ['percentage' => 25, 'days' => 30, 'calculation_base' => CalculationBase::DELIVERY_DATE],
            ['percentage' => 25, 'days' => 0, 'calculation_base' => CalculationBase::BL_DATE],
            ['percentage' => 25, 'days' => 0, 'calculation_base' => CalculationBase::BEFORE_SHIPMENT],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);
        $this->action->execute($pi);

        $items = PaymentScheduleItem::where('payable_type', ProformaInvoice::class)
            ->where('payable_id', $pi->id)
            ->get();

        $this->assertCount(4, $items);

        // All shipment-dependent stages should have null due dates
        foreach ($items as $item) {
            $this->assertNull($item->due_date, "Expected null due_date for {$item->due_condition->value}");
        }
    }

    // === regenerate() tests ===

    public function test_regenerate_recreates_unpaid_items(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 30, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);
        $this->action->execute($pi);

        // Reload with fresh total
        $pi->load('items');

        $count = $this->action->regenerate($pi);

        $this->assertEquals(2, $count);
    }

    public function test_regenerate_preserves_paid_items(): void
    {
        $term = $this->createPaymentTerm([
            ['percentage' => 30, 'days' => 0, 'calculation_base' => CalculationBase::ORDER_DATE],
            ['percentage' => 70, 'days' => 30, 'calculation_base' => CalculationBase::SHIPMENT_DATE],
        ]);

        $pi = $this->createProformaInvoice(['payment_term_id' => $term->id]);
        $this->action->execute($pi);

        // Mark first item as paid
        $firstItem = PaymentScheduleItem::where('payable_id', $pi->id)
            ->orderBy('sort_order')
            ->first();
        $firstItem->update(['status' => PaymentScheduleStatus::PAID]);

        $pi->load('items');
        $this->action->regenerate($pi);

        // Paid item should still exist
        $paidItem = PaymentScheduleItem::find($firstItem->id);
        $this->assertNotNull($paidItem);
        $this->assertEquals(PaymentScheduleStatus::PAID, $paidItem->status);
    }
}