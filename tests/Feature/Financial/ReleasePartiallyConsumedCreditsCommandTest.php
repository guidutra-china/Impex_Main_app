<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Actions\IssueCreditNoteAction;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleasePartiallyConsumedCreditsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);
    }

    public function test_releases_credit_item_stuck_paid_after_partial_consumption(): void
    {
        $creditPsi = $this->partiallyConsumedCreditPsi(3_000_000, 1_000_000);

        // Simulate the pre-fix state: binary reconcile marked it PAID.
        $creditPsi->update(['status' => PaymentScheduleStatus::PAID->value]);

        $this->artisan('financial:release-partially-consumed-credits')
            ->expectsOutputToContain('1 item(ns) liberado(s)')
            ->assertSuccessful();

        $this->assertSame(PaymentScheduleStatus::PENDING, $creditPsi->refresh()->status);
    }

    public function test_dry_run_reports_but_does_not_change_anything(): void
    {
        $creditPsi = $this->partiallyConsumedCreditPsi(3_000_000, 1_000_000);
        $creditPsi->update(['status' => PaymentScheduleStatus::PAID->value]);

        $this->artisan('financial:release-partially-consumed-credits --dry-run')
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);
    }

    public function test_fully_consumed_credit_item_is_left_alone(): void
    {
        $creditPsi = $this->partiallyConsumedCreditPsi(3_000_000, 3_000_000);

        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);

        $this->artisan('financial:release-partially-consumed-credits')
            ->expectsOutputToContain('Nenhum')
            ->assertSuccessful();

        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);
    }

    public function test_releases_additional_cost_credit_stuck_paid_after_partial_consumption(): void
    {
        $creditPsi = $this->partiallyConsumedCostCreditPsi(3_000_000, 1_000_000);

        // Simulate the pre-fix state: binary reconcile marked it PAID.
        $creditPsi->update(['status' => PaymentScheduleStatus::PAID->value]);

        $this->artisan('financial:release-partially-consumed-credits')
            ->expectsOutputToContain('1 item(ns) liberado(s)')
            ->assertSuccessful();

        $this->assertSame(PaymentScheduleStatus::PENDING, $creditPsi->refresh()->status);
    }

    /**
     * Builds a discount AdditionalCost with its credit PSI (source_type =
     * AdditionalCost — supplier deductions and discounts) and consumes
     * $consumedAmount of it through an approved outbound payment.
     */
    private function partiallyConsumedCostCreditPsi(int $creditAmount, int $consumedAmount): PaymentScheduleItem
    {
        $pi = \App\Domain\ProformaInvoices\Models\ProformaInvoice::factory()->create([
            'company_id' => $this->supplier->id,
            'currency_code' => 'USD',
        ]);

        $cost = $pi->additionalCosts()->create([
            'cost_type' => \App\Domain\Financial\Enums\AdditionalCostType::DISCOUNT->value,
            'description' => 'Goodwill discount',
            'amount' => $creditAmount,
            'currency_code' => 'USD',
            'exchange_rate' => 1,
            'amount_in_document_currency' => $creditAmount,
            'billable_to' => \App\Domain\Financial\Enums\BillableTo::SUPPLIER->value,
            'supplier_company_id' => $this->supplier->id,
            'status' => \App\Domain\Financial\Enums\AdditionalCostStatus::PENDING->value,
        ]);

        $creditPsi = PaymentScheduleItem::create([
            'payable_type' => \App\Domain\ProformaInvoices\Models\ProformaInvoice::class,
            'payable_id' => $pi->id,
            'source_type' => \App\Domain\Financial\Models\AdditionalCost::class,
            'source_id' => $cost->id,
            'label' => 'Discount: Goodwill discount',
            'percentage' => 100,
            'amount' => $creditAmount,
            'currency_code' => 'USD',
            'status' => PaymentScheduleStatus::PENDING,
            'is_credit' => true,
        ]);

        $this->consumeCredit($creditPsi, $consumedAmount);

        return $creditPsi->refresh();
    }

    /**
     * Issues a CN with a single line of $creditAmount and consumes
     * $consumedAmount of it through an approved outbound payment.
     */
    private function partiallyConsumedCreditPsi(int $creditAmount, int $consumedAmount): PaymentScheduleItem
    {
        $creditNote = CreditNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => $creditAmount,
            'currency_code' => 'USD',
            'status' => CreditNoteStatus::DRAFT,
        ]);

        CreditNoteLineItem::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Quality deduction',
            'amount' => $creditAmount,
            'currency_code' => 'USD',
        ]);

        app(IssueCreditNoteAction::class)->execute($creditNote);

        $creditPsi = PaymentScheduleItem::where('payable_type', CreditNote::class)
            ->where('payable_id', $creditNote->id)
            ->firstOrFail();

        $this->consumeCredit($creditPsi, $consumedAmount);

        return $creditPsi->refresh();
    }

    /** Consumes $consumedAmount of the credit through an approved outbound payment. */
    private function consumeCredit(PaymentScheduleItem $creditPsi, int $consumedAmount): void
    {
        $shipment = Shipment::create([
            'reference' => 'SHIP-CN-'.uniqid(),
            'company_id' => $this->supplier->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
        ]);

        $targetPsi = PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'label' => 'Balance payment',
            'percentage' => 100,
            'amount' => 5_000_000,
            'currency_code' => 'USD',
            'due_date' => '2026-07-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
        ]);

        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 0,
            'currency_code' => 'USD',
            'payment_date' => '2026-07-23',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $targetPsi->id,
            'credit_schedule_item_id' => $creditPsi->id,
            'allocated_amount' => 0,
            'exchange_rate' => null,
            'allocated_amount_in_document_currency' => $consumedAmount,
        ]);

        app(ApprovePaymentAction::class)->approve($allocation->payment);
    }
}
