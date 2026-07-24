<?php

namespace Tests\Feature;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Services\ShipmentPaymentSummaryService;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Enums\CalculationBase;
use Database\Factories\PaymentScheduleItemFactory;
use Database\Factories\ProformaInvoiceItemFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShipmentPaymentSummaryServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeShipment(): Shipment
    {
        return Shipment::factory()->create(['currency_code' => 'USD']);
    }

    private function makeShipLinkedPiStage(
        Shipment $shipment,
        ProformaInvoice $pi,
        CalculationBase $condition,
        int $percentage,
        int $amount,
    ): PaymentScheduleItem {
        return PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipment->id,
            'percentage' => $percentage,
            'amount' => $amount,
            'due_condition' => $condition,
            'label' => "{$percentage}% — stage",
        ]);
    }

    private function allocateApproved(PaymentScheduleItem $psi, int $amount): void
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => Company::factory()->create()->id,
            'amount' => $amount,
            'currency_code' => $psi->currency_code,
            'payment_date' => now()->toDateString(),
            'status' => PaymentStatus::APPROVED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $amount,
            'allocated_amount_in_document_currency' => $amount,
        ]);
    }

    public function test_client_summary_groups_ship_linked_stages_across_pis_with_same_terms(): void
    {
        $shipment = $this->makeShipment();
        $piA = ProformaInvoice::factory()->create();
        $piB = ProformaInvoice::factory()->create();

        $this->makeShipLinkedPiStage($shipment, $piA, CalculationBase::BEFORE_SHIPMENT, 30, 30_000);
        $this->makeShipLinkedPiStage($shipment, $piB, CalculationBase::BEFORE_SHIPMENT, 30, 60_000);
        $this->makeShipLinkedPiStage($shipment, $piA, CalculationBase::DELIVERY_DATE, 70, 70_000);
        $this->makeShipLinkedPiStage($shipment, $piB, CalculationBase::DELIVERY_DATE, 70, 140_000);

        $sections = app(ShipmentPaymentSummaryService::class)->forClient($shipment);

        $this->assertCount(1, $sections, 'Single-currency shipment must produce one section.');
        $section = $sections[0];
        $this->assertSame('USD', $section['currency']);
        $this->assertCount(2, $section['stages'], 'Same condition across PIs must merge into one stage row.');

        $beforeShipment = collect($section['stages'])->firstWhere('condition', CalculationBase::BEFORE_SHIPMENT->value);
        $this->assertSame(90_000, $beforeShipment['amount']);
        $this->assertSame(30, $beforeShipment['nominal_percentage'], 'Uniform nominal % must be surfaced.');

        $this->assertSame(300_000, $section['totals']['amount']);
        $this->assertSame(0, $section['totals']['paid']);
    }

    public function test_client_summary_with_mixed_terms_groups_by_condition_and_drops_nominal_percentage(): void
    {
        $shipment = $this->makeShipment();
        $piA = ProformaInvoice::factory()->create();
        $piB = ProformaInvoice::factory()->create();

        // PI A pays 30% before shipment; PI B pays 50% before shipment.
        $this->makeShipLinkedPiStage($shipment, $piA, CalculationBase::BEFORE_SHIPMENT, 30, 30_000);
        $this->makeShipLinkedPiStage($shipment, $piB, CalculationBase::BEFORE_SHIPMENT, 50, 50_000);

        $sections = app(ShipmentPaymentSummaryService::class)->forClient($shipment);
        $stage = $sections[0]['stages'][0];

        $this->assertSame(80_000, $stage['amount']);
        $this->assertNull($stage['nominal_percentage'], 'Mixed nominal percentages must not show a single %.');
    }

    public function test_partial_allocation_counts_as_paid_and_marks_stage_partial(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create();

        $psi = $this->makeShipLinkedPiStage($shipment, $pi, CalculationBase::BEFORE_SHIPMENT, 30, 100_000);
        $this->allocateApproved($psi, 40_000);

        $sections = app(ShipmentPaymentSummaryService::class)->forClient($shipment);
        $stage = $sections[0]['stages'][0];

        $this->assertSame(40_000, $stage['paid']);
        $this->assertSame(60_000, $stage['remaining']);
        $this->assertSame('partial', $stage['status']);
        $this->assertSame(40_000, $sections[0]['totals']['paid']);
    }

    public function test_document_level_order_date_stage_is_prorated_by_shipment_share(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create();

        // PI value: two items of 1,000,000 each => 2,000,000 total.
        $itemInShipment = ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'quantity' => 100,
            'unit_price' => 10_000,
        ]);
        ProformaInvoiceItemFactory::new()->create([
            'proforma_invoice_id' => $pi->id,
            'quantity' => 100,
            'unit_price' => 10_000,
        ]);

        // The shipment carries only the first item => 50% of the PI value.
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $itemInShipment->id,
            'quantity' => 100,
            'unit' => 'pcs',
        ]);

        // Document-level stage: 10% on order date (not shipment-linked), fully paid.
        $orderStage = PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'percentage' => 10,
            'amount' => 200_000,
            'due_condition' => CalculationBase::ORDER_DATE,
        ]);
        $this->allocateApproved($orderStage, 200_000);

        // A [remaining]-style row (shipment condition, no shipment link) must stay out.
        PaymentScheduleItemFactory::new()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => null,
            'percentage' => 90,
            'amount' => 1_800_000,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        $sections = app(ShipmentPaymentSummaryService::class)->forClient($shipment);
        $this->assertCount(1, $sections[0]['stages'], 'Only the prorated order-date stage should appear.');

        $stage = $sections[0]['stages'][0];
        $this->assertSame(CalculationBase::ORDER_DATE->value, $stage['condition']);
        $this->assertTrue($stage['prorated']);
        $this->assertSame(100_000, $stage['amount'], '10% of the shipment share (1,000,000).');
        $this->assertSame(100_000, $stage['paid'], 'Fully paid stage prorates fully.');
        $this->assertSame('paid', $stage['status']);
    }

    public function test_supplier_summary_scopes_to_the_given_supplier_company(): void
    {
        $shipment = $this->makeShipment();
        $supplierA = Company::factory()->create();
        $supplierB = Company::factory()->create();
        $poA = PurchaseOrder::factory()->create(['supplier_company_id' => $supplierA->id]);
        $poB = PurchaseOrder::factory()->create(['supplier_company_id' => $supplierB->id]);

        PaymentScheduleItemFactory::new()->create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $poA->id,
            'shipment_id' => $shipment->id,
            'percentage' => 30,
            'amount' => 30_000,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
        ]);
        PaymentScheduleItemFactory::new()->create([
            'payable_type' => PurchaseOrder::class,
            'payable_id' => $poB->id,
            'shipment_id' => $shipment->id,
            'percentage' => 30,
            'amount' => 99_000,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        $service = app(ShipmentPaymentSummaryService::class);

        $all = $service->forSupplier($shipment);
        $this->assertSame(129_000, $all[0]['totals']['amount'], 'Unscoped supplier summary sums every PO.');

        $scoped = $service->forSupplier($shipment, $supplierA->id);
        $this->assertSame(30_000, $scoped[0]['totals']['amount'], 'Scoped summary must only include that supplier\'s POs.');
    }

    public function test_mirror_shipment_payable_rows_and_credits_are_ignored(): void
    {
        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create();

        $this->makeShipLinkedPiStage($shipment, $pi, CalculationBase::BEFORE_SHIPMENT, 30, 50_000);

        // Mirror row owned by the shipment (same numbers) must not double count.
        PaymentScheduleItemFactory::new()->create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'shipment_id' => $shipment->id,
            'percentage' => 30,
            'amount' => 50_000,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        // Credit lines are settlement artifacts, not receivable stages.
        PaymentScheduleItemFactory::new()->credit()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipment->id,
            'percentage' => 0,
            'amount' => 10_000,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        $sections = app(ShipmentPaymentSummaryService::class)->forClient($shipment);

        $this->assertSame(50_000, $sections[0]['totals']['amount']);
    }

    public function test_open_client_remainder_by_shipment_sums_open_stages_per_currency(): void
    {
        $shipmentA = $this->makeShipment();
        $shipmentB = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create();

        // Shipment A: 100k stage with 40k paid => 60k open; a PAID stage is ignored.
        $psi = $this->makeShipLinkedPiStage($shipmentA, $pi, CalculationBase::DELIVERY_DATE, 60, 100_000);
        $this->allocateApproved($psi, 40_000);
        PaymentScheduleItemFactory::new()->paid()->create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'shipment_id' => $shipmentA->id,
            'percentage' => 30,
            'amount' => 50_000,
            'due_condition' => CalculationBase::BEFORE_SHIPMENT,
        ]);

        // Shipment B: untouched 70k stage.
        $this->makeShipLinkedPiStage($shipmentB, $pi, CalculationBase::DELIVERY_DATE, 70, 70_000);

        $remainders = app(ShipmentPaymentSummaryService::class)
            ->openClientRemainderByShipment([$shipmentA->id, $shipmentB->id]);

        $this->assertSame(60_000, $remainders[$shipmentA->id]['USD']);
        $this->assertSame(70_000, $remainders[$shipmentB->id]['USD']);
    }

    public function test_presenter_builds_display_ready_blocks(): void
    {
        $presenter = new class
        {
            use \App\Filament\Concerns\PresentsShipmentPaymentSummary;

            public function build(string $title, array $sections): ?array
            {
                return $this->buildPaymentSummaryBlock($title, $sections);
            }
        };

        $shipment = $this->makeShipment();
        $pi = ProformaInvoice::factory()->create();
        $psi = $this->makeShipLinkedPiStage($shipment, $pi, CalculationBase::BEFORE_SHIPMENT, 30, 100_000);
        $this->allocateApproved($psi, 40_000);

        $sections = app(ShipmentPaymentSummaryService::class)->forClient($shipment);
        $block = $presenter->build('Recebimentos', $sections);

        $this->assertSame('Recebimentos', $block['title']);
        $stage = $block['sections'][0]['stages'][0];
        $this->assertStringStartsWith('30% — ', $stage['label']);
        $this->assertSame('partial', $stage['status']);
        $this->assertTrue($stage['has_difference'], 'Paid below total must flag a difference.');
        $this->assertSame('4.00', $stage['paid']);
        $this->assertSame('10.00', $stage['amount']);
        $this->assertSame('6.00', $stage['remaining']);

        $this->assertNull($presenter->build('Vazio', []), 'Empty sections must collapse the whole block.');
    }
}
