<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\IssueCreditNoteAction;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Actions\SyncCreditNoteScheduleAction;
use App\Domain\Financial\Actions\SyncDebitNoteScheduleAction;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\Payment;
use App\Domain\Financial\Models\PaymentAllocation;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncNoteScheduleFromLineItemsTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);
    }

    public function test_removes_orphaned_item_and_creates_missing_for_debit_note(): void
    {
        // DN-2026-0006 regression: the edit page deletes and recreates line
        // items, orphaning the issued PSI and leaving the new line without one.
        $debitNote = $this->issuedDebitNote('5% discount', 60_121_500);
        $oldLine = $debitNote->lineItems()->firstOrFail();
        $orphanPsi = $this->scheduleItemFor(DebitNoteLineItem::class, $oldLine->id);

        $oldLine->delete();
        $newLine = DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'description' => 'embroidered logo',
            'amount' => 5_940_000,
            'currency_code' => 'USD',
        ]);

        app(SyncDebitNoteScheduleAction::class)->execute($debitNote);

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $orphanPsi->id]);

        $newPsi = $this->scheduleItemFor(DebitNoteLineItem::class, $newLine->id);
        $this->assertSame(5_940_000, $newPsi->amount);
        $this->assertStringContainsString('embroidered logo', $newPsi->label);
        $this->assertFalse((bool) $newPsi->is_credit);
    }

    public function test_updates_schedule_item_when_line_amount_changes(): void
    {
        $debitNote = $this->issuedDebitNote('Logo fee', 1_000_000);
        $line = $debitNote->lineItems()->firstOrFail();

        $line->update(['amount' => 2_000_000, 'description' => 'Logo fee (revised)']);

        app(SyncDebitNoteScheduleAction::class)->execute($debitNote);

        $psi = $this->scheduleItemFor(DebitNoteLineItem::class, $line->id);
        $this->assertSame(2_000_000, $psi->amount);
        $this->assertStringContainsString('Logo fee (revised)', $psi->label);
    }

    public function test_orphan_with_allocations_is_kept_and_reported(): void
    {
        $debitNote = $this->issuedDebitNote('Tooling', 1_000_000);
        $line = $debitNote->lineItems()->firstOrFail();
        $psi = $this->scheduleItemFor(DebitNoteLineItem::class, $line->id);

        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-07-23',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => 1_000_000,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 1_000_000,
        ]);

        $line->delete();

        $changes = app(SyncDebitNoteScheduleAction::class)->execute($debitNote);

        $this->assertDatabaseHas('payment_schedule_items', ['id' => $psi->id]);
        $this->assertTrue(
            collect($changes)->contains(fn ($c) => str_contains($c, 'SKIP')),
            'Sync must report the allocated orphan it refused to delete.'
        );
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $debitNote = $this->issuedDebitNote('5% discount', 60_121_500);
        $oldLine = $debitNote->lineItems()->firstOrFail();
        $orphanPsi = $this->scheduleItemFor(DebitNoteLineItem::class, $oldLine->id);
        $oldLine->delete();

        $changes = app(SyncDebitNoteScheduleAction::class)->execute($debitNote, dryRun: true);

        $this->assertNotEmpty($changes);
        $this->assertDatabaseHas('payment_schedule_items', ['id' => $orphanPsi->id]);
    }

    public function test_draft_debit_note_is_untouched(): void
    {
        $debitNote = DebitNote::create($this->debitNoteAttributes(1_000_000));
        DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'description' => 'Draft line',
            'amount' => 1_000_000,
            'currency_code' => 'USD',
        ]);

        $changes = app(SyncDebitNoteScheduleAction::class)->execute($debitNote);

        $this->assertSame([], $changes);
        $this->assertSame(0, PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $debitNote->id)->count());
    }

    public function test_cancelled_debit_note_has_unallocated_schedule_items_removed(): void
    {
        // Cancelling only flips the note status; its PSIs kept showing up in
        // AR/AP open items (DN-2026-0004 / CN-2026-0002 case).
        $debitNote = $this->issuedDebitNote('teste', 4_700_000);
        $line = $debitNote->lineItems()->firstOrFail();
        $psi = $this->scheduleItemFor(DebitNoteLineItem::class, $line->id);

        $debitNote->update(['status' => DebitNoteStatus::CANCELLED]);

        $changes = app(SyncDebitNoteScheduleAction::class)->execute($debitNote->refresh());

        $this->assertNotEmpty($changes);
        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $psi->id]);
    }

    public function test_cancelled_debit_note_keeps_allocated_schedule_item(): void
    {
        $debitNote = $this->issuedDebitNote('Tooling', 1_000_000);
        $line = $debitNote->lineItems()->firstOrFail();
        $psi = $this->scheduleItemFor(DebitNoteLineItem::class, $line->id);

        $payment = Payment::create([
            'direction' => PaymentDirection::INBOUND,
            'company_id' => $this->supplier->id,
            'amount' => 1_000_000,
            'currency_code' => 'USD',
            'payment_date' => '2026-07-23',
            'status' => PaymentStatus::PENDING_APPROVAL,
        ]);
        PaymentAllocation::create([
            'payment_id' => $payment->id,
            'payment_schedule_item_id' => $psi->id,
            'allocated_amount' => 1_000_000,
            'exchange_rate' => 1,
            'allocated_amount_in_document_currency' => 1_000_000,
        ]);

        $debitNote->update(['status' => DebitNoteStatus::CANCELLED]);

        $changes = app(SyncDebitNoteScheduleAction::class)->execute($debitNote->refresh());

        $this->assertDatabaseHas('payment_schedule_items', ['id' => $psi->id]);
        $this->assertTrue(
            collect($changes)->contains(fn ($c) => str_contains($c, 'SKIP')),
        );
    }

    public function test_cancelled_credit_note_has_credit_schedule_item_removed(): void
    {
        $creditNote = CreditNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => 4_710_000,
            'currency_code' => 'USD',
            'status' => CreditNoteStatus::DRAFT,
        ]);
        CreditNoteLineItem::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Customization Fee',
            'amount' => 4_710_000,
            'currency_code' => 'USD',
        ]);
        app(IssueCreditNoteAction::class)->execute($creditNote);
        $line = $creditNote->lineItems()->firstOrFail();
        $psi = $this->scheduleItemFor(CreditNoteLineItem::class, $line->id);

        $creditNote->update(['status' => CreditNoteStatus::CANCELLED]);

        app(SyncCreditNoteScheduleAction::class)->execute($creditNote->refresh());

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $psi->id]);
    }

    public function test_credit_note_sync_replaces_orphan_and_keeps_credit_flag(): void
    {
        $creditNote = CreditNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => 3_000_000,
            'currency_code' => 'USD',
            'status' => CreditNoteStatus::DRAFT,
        ]);
        CreditNoteLineItem::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Quality deduction',
            'amount' => 3_000_000,
            'currency_code' => 'USD',
        ]);
        app(IssueCreditNoteAction::class)->execute($creditNote);
        $creditNote->refresh();

        $oldLine = $creditNote->lineItems()->firstOrFail();
        $orphanPsi = $this->scheduleItemFor(CreditNoteLineItem::class, $oldLine->id);

        $oldLine->delete();
        $newLine = CreditNoteLineItem::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Rework deduction',
            'amount' => 2_500_000,
            'currency_code' => 'USD',
        ]);
        $creditNote->update(['total_amount' => 2_500_000]);

        app(SyncCreditNoteScheduleAction::class)->execute($creditNote);

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $orphanPsi->id]);

        $newPsi = $this->scheduleItemFor(CreditNoteLineItem::class, $newLine->id);
        $this->assertSame(2_500_000, $newPsi->amount);
        $this->assertTrue((bool) $newPsi->is_credit);
        $this->assertSame(CreditNoteStatus::ISSUED, $creditNote->refresh()->status);
    }

    /**
     * @return array<string, mixed>
     */
    private function debitNoteAttributes(int $amount): array
    {
        return [
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => $amount,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ];
    }

    private function issuedDebitNote(string $description, int $amount): DebitNote
    {
        $debitNote = DebitNote::create($this->debitNoteAttributes($amount));

        DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'description' => $description,
            'amount' => $amount,
            'currency_code' => 'USD',
        ]);

        app(IssueDebitNoteAction::class)->execute($debitNote);

        return $debitNote->refresh();
    }

    private function scheduleItemFor(string $sourceType, int $sourceId): PaymentScheduleItem
    {
        return PaymentScheduleItem::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->firstOrFail();
    }
}
