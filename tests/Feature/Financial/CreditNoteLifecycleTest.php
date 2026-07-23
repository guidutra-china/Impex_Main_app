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
use App\Filament\Resources\Finance\Concerns\HasPaymentFormSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditNoteLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);
    }

    public function test_issuing_creates_credit_schedule_items(): void
    {
        $creditNote = $this->draftCreditNote([3_000_000, 2_000_000]);

        app(IssueCreditNoteAction::class)->execute($creditNote);

        $creditNote->refresh();
        $this->assertSame(CreditNoteStatus::ISSUED, $creditNote->status);
        $this->assertNotNull($creditNote->issued_at);

        $items = PaymentScheduleItem::where('payable_type', CreditNote::class)
            ->where('payable_id', $creditNote->id)
            ->get();

        $this->assertCount(2, $items);
        $this->assertTrue($items->every(fn ($i) => $i->is_credit));
        $this->assertTrue($items->every(fn ($i) => $i->status === PaymentScheduleStatus::PENDING));
        $this->assertSame(5_000_000, (int) $items->sum('amount'));
    }

    public function test_issuing_requires_draft_and_line_items(): void
    {
        $empty = CreditNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'currency_code' => 'USD',
            'status' => CreditNoteStatus::DRAFT,
        ]);

        $this->expectException(\RuntimeException::class);
        app(IssueCreditNoteAction::class)->execute($empty);
    }

    public function test_applying_credit_settles_target_item_and_marks_note_applied(): void
    {
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();
        $targetPsi = $this->openSupplierItem(3_000_000);

        $allocation = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi);

        // Pending payment: nothing settled yet (CN stays ISSUED).
        $this->assertSame(CreditNoteStatus::ISSUED, $creditNote->refresh()->status);

        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $this->assertSame(CreditNoteStatus::APPLIED, $creditNote->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PAID, $targetPsi->refresh()->status);
        $this->assertSame(3_000_000, $creditNote->applied_amount);
        $this->assertSame(0, $creditNote->remaining_amount);
    }

    public function test_cancelling_consuming_payment_releases_the_credit(): void
    {
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();
        $targetPsi = $this->openSupplierItem(3_000_000);

        $allocation = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi);
        $action = app(ApprovePaymentAction::class);

        $action->approve($allocation->payment);
        $this->assertSame(CreditNoteStatus::APPLIED, $creditNote->refresh()->status);

        $action->cancel($allocation->payment->refresh(), 'application reversed');

        $this->assertSame(CreditNoteStatus::ISSUED, $creditNote->refresh()->status);
        // Credit becomes available again for a future application.
        $this->assertSame(PaymentScheduleStatus::PENDING, $creditPsi->refresh()->status);
        $this->assertNotSame(PaymentScheduleStatus::PAID, $targetPsi->refresh()->status);
    }

    public function test_cash_refund_marks_note_refunded(): void
    {
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();

        // Supplier wires the claim back: inbound payment allocated directly
        // to the credit schedule item.
        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 3_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-06-11',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        $allocation = PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $creditPsi->id,
            'allocated_amount' => 3_000_000,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 3_000_000,
        ]);

        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $creditNote->refresh();
        $this->assertSame(CreditNoteStatus::REFUNDED, $creditNote->status);
        $this->assertSame(3_000_000, $creditNote->refunded_amount);
        $this->assertSame(0, $creditNote->applied_amount);
    }

    public function test_partial_application_marks_note_partially_applied(): void
    {
        $creditNote = $this->issuedCreditNote([3_000_000, 2_000_000]);
        $creditItems = $this->creditScheduleItems($creditNote);
        $targetPsi = $this->openSupplierItem(3_000_000);

        // Apply only the first credit line.
        $allocation = $this->applyCreditViaPendingPayment($creditItems->first(), $targetPsi);
        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $this->assertSame(CreditNoteStatus::PARTIALLY_APPLIED, $creditNote->refresh()->status);
        $this->assertSame(2_000_000, $creditNote->remaining_amount);
    }

    public function test_partial_application_keeps_credit_item_available(): void
    {
        // CN-2026-0004 regression: applying part of a credit must NOT mark
        // the credit PSI as PAID — the remaining balance has to stay visible
        // in the payment form's available-credits list.
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();
        $targetPsi = $this->openSupplierItem(5_000_000);

        $allocation = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi, 1_000_000);
        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $this->assertSame(CreditNoteStatus::PARTIALLY_APPLIED, $creditNote->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PENDING, $creditPsi->refresh()->status);
        $this->assertSame(1_000_000, $creditPsi->credit_consumed_amount);
        $this->assertSame(2_000_000, $creditPsi->credit_remaining_amount);
    }

    public function test_credit_item_fully_consumed_across_partial_applications_marks_paid(): void
    {
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();
        $targetPsi = $this->openSupplierItem(5_000_000);

        $first = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi, 1_000_000);
        app(ApprovePaymentAction::class)->approve($first->payment);

        $second = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi, 2_000_000);
        app(ApprovePaymentAction::class)->approve($second->payment);

        $this->assertSame(CreditNoteStatus::APPLIED, $creditNote->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);
        $this->assertSame(0, $creditPsi->credit_remaining_amount);
    }

    public function test_cancelling_one_of_two_partial_consumptions_reopens_the_credit(): void
    {
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();
        $targetPsi = $this->openSupplierItem(5_000_000);
        $action = app(ApprovePaymentAction::class);

        $first = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi, 1_000_000);
        $action->approve($first->payment);

        $second = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi, 2_000_000);
        $action->approve($second->payment);
        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);

        $action->cancel($second->payment->refresh(), 'application reversed');

        $this->assertSame(CreditNoteStatus::PARTIALLY_APPLIED, $creditNote->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PENDING, $creditPsi->refresh()->status);
        $this->assertSame(2_000_000, $creditPsi->credit_remaining_amount);
    }

    public function test_partially_applied_credit_still_offered_in_payment_form_with_remaining_amount(): void
    {
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();
        $targetPsi = $this->openSupplierItem(5_000_000);

        $allocation = $this->applyCreditViaPendingPayment($creditPsi, $targetPsi, 1_000_000);
        app(ApprovePaymentAction::class)->approve($allocation->payment);

        $sections = new class
        {
            use HasPaymentFormSections;
        };

        $credits = $sections::getCompanyCreditItems($this->supplier->id, PaymentDirection::OUTBOUND);
        $this->assertTrue(
            $credits->contains('id', $creditPsi->id),
            'Partially applied credit must remain in the available-credits list.'
        );

        $label = $sections::formatCreditItemLabel($credits->firstWhere('id', $creditPsi->id));
        $this->assertStringContainsString('200.00', $label, 'Label must show the remaining balance.');
        $this->assertStringContainsString('300.00', $label, 'Label must show the original credit total.');
    }

    public function test_applying_credit_to_already_approved_payment_consumes_immediately(): void
    {
        // Mirrors the "Manage Allocations" modal on an approved outgoing
        // payment: the allocation is created while the payment is already
        // APPROVED, so the observer must consume the credit on creation
        // (no later approve() step).
        $creditNote = $this->issuedCreditNote([3_000_000]);
        $creditPsi = $this->creditScheduleItems($creditNote)->firstOrFail();
        $targetPsi = $this->openSupplierItem(3_000_000);

        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 0,
            'currency_code' => 'USD',
            'payment_date' => '2026-06-11',
            'status' => PaymentStatus::APPROVED,
        ]);

        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $targetPsi->id,
            'credit_schedule_item_id' => $creditPsi->id,
            'allocated_amount' => 0,
            'exchange_rate' => null,
            'allocated_amount_in_document_currency' => $creditPsi->amount,
        ]);

        $this->assertSame(CreditNoteStatus::APPLIED, $creditNote->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PAID, $creditPsi->refresh()->status);
        $this->assertSame(PaymentScheduleStatus::PAID, $targetPsi->refresh()->status);
        $this->assertSame(3_000_000, $creditNote->applied_amount);
    }

    public function test_reference_uses_max_plus_one_even_after_force_delete(): void
    {
        $first = $this->draftCreditNote([1_000_000]);
        $second = $this->draftCreditNote([1_000_000]);

        $year = now()->year;
        $this->assertSame(sprintf('CN-%d-0001', $year), $first->reference);
        $this->assertSame(sprintf('CN-%d-0002', $year), $second->reference);

        // count()+1 would regress to 0002 here and collide with $second.
        $first->forceDelete();

        $third = $this->draftCreditNote([1_000_000]);
        $this->assertSame(sprintf('CN-%d-0003', $year), $third->reference);
    }

    /**
     * @param  int[]  $lineAmounts
     */
    private function draftCreditNote(array $lineAmounts): CreditNote
    {
        $creditNote = CreditNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => array_sum($lineAmounts),
            'currency_code' => 'USD',
            'status' => CreditNoteStatus::DRAFT,
        ]);

        foreach ($lineAmounts as $i => $amount) {
            CreditNoteLineItem::create([
                'credit_note_id' => $creditNote->id,
                'description' => 'Quality deduction '.($i + 1),
                'amount' => $amount,
                'currency_code' => 'USD',
            ]);
        }

        return $creditNote;
    }

    /**
     * @param  int[]  $lineAmounts
     */
    private function issuedCreditNote(array $lineAmounts): CreditNote
    {
        $creditNote = $this->draftCreditNote($lineAmounts);

        app(IssueCreditNoteAction::class)->execute($creditNote);

        return $creditNote->refresh();
    }

    private function creditScheduleItems(CreditNote $creditNote)
    {
        return PaymentScheduleItem::where('payable_type', CreditNote::class)
            ->where('payable_id', $creditNote->id)
            ->orderBy('sort_order')
            ->get();
    }

    private function openSupplierItem(int $amount): PaymentScheduleItem
    {
        $shipment = Shipment::create([
            'reference' => 'SHIP-CN-'.uniqid(),
            'company_id' => $this->supplier->id,
            'status' => ShipmentStatus::IN_TRANSIT,
            'currency_code' => 'USD',
        ]);

        return PaymentScheduleItem::create([
            'payable_type' => Shipment::class,
            'payable_id' => $shipment->id,
            'label' => 'Balance payment',
            'percentage' => 100,
            'amount' => $amount,
            'currency_code' => 'USD',
            'due_date' => '2026-07-01',
            'status' => PaymentScheduleStatus::DUE,
            'is_credit' => false,
        ]);
    }

    private function applyCreditViaPendingPayment(PaymentScheduleItem $creditPsi, PaymentScheduleItem $targetPsi, ?int $amount = null): PaymentAllocation
    {
        $payment = Payment::create([
            'direction' => PaymentDirection::OUTBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 0,
            'currency_code' => 'USD',
            'payment_date' => '2026-06-11',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);

        return PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $targetPsi->id,
            'credit_schedule_item_id' => $creditPsi->id,
            'allocated_amount' => 0,
            'exchange_rate' => null,
            'allocated_amount_in_document_currency' => $amount ?? $creditPsi->amount,
        ]);
    }
}
