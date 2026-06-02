<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\Settings\Enums\CalculationBase;
use App\Domain\Settings\Models\PaymentTerm;
use App\Domain\Settings\Models\PaymentTermStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the read-only production audit `financial:audit-psi-duplicates`:
 * it must FAIL (exit != 0) when a paid [remaining] coexists with a ship-specific
 * item for the same stage (double-count), and PASS on clean data — without ever
 * mutating records.
 */
class AuditPaymentScheduleDuplicatesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Inquiry $inquiry;

    private PaymentTermStage $shipmentStage;

    private int $termId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Audit Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->inquiry = Inquiry::create([
            'reference' => 'INQ-AUDIT-001',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $term = PaymentTerm::create(['name' => 'Audit Term', 'is_active' => true]);
        $this->shipmentStage = PaymentTermStage::create([
            'payment_term_id' => $term->id, 'sort_order' => 1,
            'percentage' => 100, 'days' => 0, 'calculation_base' => CalculationBase::SHIPMENT_DATE,
        ]);
        $this->termId = $term->id;
    }

    private function makePi(string $ref): ProformaInvoice
    {
        $pi = ProformaInvoice::create([
            'reference' => $ref,
            'inquiry_id' => $this->inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-03-01',
            'status' => 'confirmed',
            'payment_term_id' => $this->termId,
        ]);
        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'description' => 'Widget',
            'quantity' => 100,
            'unit_price' => 1000,
            'sort_order' => 1,
        ]);

        return $pi;
    }

    private function paidItem(ProformaInvoice $pi, int $amount, ?int $shipmentId, string $label): PaymentScheduleItem
    {
        $item = PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'payment_term_stage_id' => $this->shipmentStage->id,
            'shipment_id' => $shipmentId,
            'label' => $label,
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
            'status' => PaymentScheduleStatus::PAID,
            'sort_order' => 1,
        ]);

        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->client->id,
            'amount' => $amount,
            'currency_code' => 'USD',
            'payment_date' => '2026-03-05',
            'reference' => 'PAY-'.$pi->reference.'-'.$item->id,
            'status' => PaymentStatus::APPROVED,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $item->id,
            'allocated_amount' => $amount,
            'exchange_rate' => 1.0,
            'allocated_amount_in_document_currency' => $amount,
            'created_at' => '2026-03-05',
        ]);

        return $item;
    }

    private function paidRemaining(ProformaInvoice $pi, int $amount = 100_000): PaymentScheduleItem
    {
        return $this->paidItem($pi, $amount, null, '100% — Shipment Date — ['.$pi->reference.'] [remaining]');
    }

    private function makeShipment(string $ref): Shipment
    {
        return Shipment::create([
            'reference' => $ref,
            'company_id' => $this->client->id,
            'issue_date' => '2026-03-20',
            'status' => ShipmentStatus::BOOKED,
            'currency_code' => 'USD',
            'created_by' => null,
        ]);
    }

    public function test_audit_fails_when_paid_remaining_duplicated_with_ship_specific(): void
    {
        $pi = $this->makePi('PI-AUDIT-DUP');
        $remaining = $this->paidRemaining($pi);
        $shipment = $this->makeShipment('SHP-AUDIT-DUP');

        // The bug state: an empty ship-specific clone for the same stage.
        PaymentScheduleItem::create([
            'payable_type' => ProformaInvoice::class,
            'payable_id' => $pi->id,
            'payment_term_stage_id' => $this->shipmentStage->id,
            'shipment_id' => $shipment->id,
            'label' => '100% — Shipment Date — [SHP-X / '.$pi->reference.']',
            'percentage' => 100,
            'amount' => 100_000,
            'currency_code' => 'USD',
            'due_condition' => CalculationBase::SHIPMENT_DATE,
            'status' => PaymentScheduleStatus::PENDING,
            'sort_order' => 2,
        ]);

        $this->artisan('financial:audit-psi-duplicates')
            ->assertExitCode(1)
            ->expectsOutputToContain('PI-AUDIT-DUP');

        // Read-only: nothing was deleted or mutated.
        $this->assertSame(2, PaymentScheduleItem::where('payable_id', $pi->id)->count());
        $this->assertSame(PaymentScheduleStatus::PAID, $remaining->refresh()->status);
    }

    public function test_audit_passes_for_legitimate_partial_shipment(): void
    {
        // Mirrors prod PI-2026-00029: PI fully pre-paid, one shipment carved out
        // part of the value (ship-specific) and the rest stays as [remaining].
        // Amounts reconcile to the stage total → NOT a double-count.
        $pi = $this->makePi('PI-AUDIT-PARTIAL'); // total = 100 × 1000 = 100,000
        $shipment = $this->makeShipment('SHP-AUDIT-PARTIAL');

        $this->paidItem($pi, 70_000, null, '100% — Shipment Date — ['.$pi->reference.'] [remaining]');
        $this->paidItem($pi, 30_000, $shipment->id, '100% — Shipment Date — [SHP-AUDIT-PARTIAL / '.$pi->reference.']');

        // 70,000 + 30,000 = 100,000 = stage share of PI total → no over-scheduling.
        $this->artisan('financial:audit-psi-duplicates')
            ->assertExitCode(0)
            ->expectsOutputToContain('nenhuma contagem dupla');
    }

    public function test_audit_passes_for_unshipped_paid_remaining(): void
    {
        // Paid before shipment, but no ship-specific sibling yet → legit canonical.
        $pi = $this->makePi('PI-AUDIT-CLEAN');
        $this->paidRemaining($pi);

        $this->artisan('financial:audit-psi-duplicates')
            ->assertExitCode(0)
            ->expectsOutputToContain('nenhuma contagem dupla');
    }

    public function test_audit_passes_after_promotion(): void
    {
        // Promoted state: the paid item is now ship-specific, no null [remaining].
        $pi = $this->makePi('PI-AUDIT-PROMOTED');
        $remaining = $this->paidRemaining($pi);
        $shipment = $this->makeShipment('SHP-AUDIT-PROMOTED');
        $remaining->update(['shipment_id' => $shipment->id, 'label' => '100% — Shipment Date — [SHP-Y / '.$pi->reference.']']);

        $this->artisan('financial:audit-psi-duplicates')
            ->assertExitCode(0);
    }
}
