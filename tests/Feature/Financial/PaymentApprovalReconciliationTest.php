<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\ApprovePaymentAction;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\AdditionalCost;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: the normal operator flow is allocate-then-approve. The
 * allocation observer fires while the payment is still PENDING_APPROVAL
 * (paid_amount = 0 at that point), so document-level state (DebitNote
 * status, AdditionalCost status) must be reconciled again at approval and
 * cancellation time by ApprovePaymentAction.
 */
class PaymentApprovalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private Company $clientCompany;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clientCompany = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $this->clientCompany->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);
    }

    public function test_approving_payment_marks_debit_note_paid(): void
    {
        $debitNote = $this->issuedDebitNote();
        $psi = $this->debitNoteScheduleItem($debitNote);

        $allocation = $this->allocatePendingPayment($psi);

        // Allocation alone (payment pending) must not settle anything.
        $this->assertSame(DebitNoteStatus::ISSUED, $debitNote->refresh()->status);
        $this->assertNotSame(PaymentScheduleStatus::PAID, $psi->refresh()->status);

        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $this->assertSame(PaymentScheduleStatus::PAID, $psi->refresh()->status);
        $this->assertSame(DebitNoteStatus::PAID, $debitNote->refresh()->status);
    }

    public function test_partial_payment_marks_debit_note_partially_paid(): void
    {
        $debitNote = $this->issuedDebitNote(lineAmounts: [5_000_000, 5_000_000]);
        $scheduleItems = $this->debitNoteScheduleItems($debitNote);

        // Pay only the first line item.
        $allocation = $this->allocatePendingPayment($scheduleItems->first());

        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $this->assertSame(DebitNoteStatus::PARTIALLY_PAID, $debitNote->refresh()->status);
    }

    public function test_cancelling_approved_payment_reverts_debit_note(): void
    {
        $debitNote = $this->issuedDebitNote();
        $psi = $this->debitNoteScheduleItem($debitNote);

        $allocation = $this->allocatePendingPayment($psi);
        $action = app(ApprovePaymentAction::class);

        $action->approve($allocation->payment);
        $this->assertSame(DebitNoteStatus::PAID, $debitNote->refresh()->status);

        $action->cancel($allocation->payment->refresh(), 'wire recalled');

        $this->assertNotSame(PaymentScheduleStatus::PAID, $psi->refresh()->status);
        $this->assertSame(DebitNoteStatus::ISSUED, $debitNote->refresh()->status);
    }

    public function test_approving_payment_syncs_additional_cost_status(): void
    {
        $shipment = Shipment::create([
            'reference' => 'SHIP-APPROVAL-SYNC',
            'company_id' => $this->clientCompany->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
        ]);

        $cost = AdditionalCost::create([
            'costable_type' => Shipment::class,
            'costable_id' => $shipment->id,
            'cost_type' => AdditionalCostType::CUSTOMS,
            'description' => 'Customs broker',
            'amount' => 2_000_000,
            'currency_code' => 'USD',
            'amount_in_document_currency' => 2_000_000,
            'billable_to' => BillableTo::CLIENT,
            'cost_date' => '2026-05-15',
            'status' => AdditionalCostStatus::INVOICED,
        ]);

        $psi = PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'label' => 'Customs',
            'percentage' => 100,
            'amount' => 2_000_000,
            'currency_code' => 'USD',
            'due_date' => '2026-06-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
            'source_type' => AdditionalCost::class,
            'source_id' => $cost->id,
        ]);

        $allocation = $this->allocatePendingPayment($psi);

        $this->assertNotSame(AdditionalCostStatus::PAID, $cost->refresh()->status);

        $action = app(ApprovePaymentAction::class);
        $action->approve($allocation->payment);

        $this->assertSame(AdditionalCostStatus::PAID, $cost->refresh()->status);

        $action->cancel($allocation->payment->refresh(), 'duplicated entry');

        $this->assertNotSame(AdditionalCostStatus::PAID, $cost->refresh()->status);
    }

    /**
     * @param  int[]  $lineAmounts
     */
    private function issuedDebitNote(array $lineAmounts = [10_000_000]): DebitNote
    {
        $debitNote = DebitNote::create([
            'company_id' => $this->clientCompany->id,
            'total_amount' => array_sum($lineAmounts),
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
            'due_date' => '2026-07-01',
        ]);

        foreach ($lineAmounts as $i => $amount) {
            DebitNoteLineItem::create([
                'debit_note_id' => $debitNote->id,
                'description' => 'Line '.($i + 1),
                'amount' => $amount,
                'currency_code' => 'USD',
            ]);
        }

        app(IssueDebitNoteAction::class)->execute($debitNote);

        return $debitNote->refresh();
    }

    private function debitNoteScheduleItem(DebitNote $debitNote): PaymentScheduleItem
    {
        return $this->debitNoteScheduleItems($debitNote)->firstOrFail();
    }

    private function debitNoteScheduleItems(DebitNote $debitNote)
    {
        return PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $debitNote->id)
            ->orderBy('sort_order')
            ->get();
    }

    private function allocatePendingPayment(PaymentScheduleItem $psi): PaymentAllocation
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->clientCompany->id,
            'amount' => $psi->amount,
            'currency_code' => $psi->currency_code,
            'payment_date' => '2026-06-10',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        return PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => $psi->amount,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => $psi->amount,
        ]);
    }
}
