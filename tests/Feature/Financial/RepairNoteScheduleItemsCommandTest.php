<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\IssueCreditNoteAction;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
use App\Domain\Financial\Enums\CreditNoteStatus;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Enums\PartyType;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\CreditNoteLineItem;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\DebitNoteLineItem;
use App\Domain\Financial\Models\PaymentScheduleItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairNoteScheduleItemsCommandTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);
    }

    public function test_repairs_orphaned_and_missing_schedule_items_for_both_note_types(): void
    {
        [$debitNote, $dnOrphanPsi, $dnNewLine] = $this->debitNoteWithLegacyEditDamage();
        [$creditNote, $cnOrphanPsi, $cnNewLine] = $this->creditNoteWithLegacyEditDamage();

        $this->artisan('financial:repair-note-schedule-items')
            ->assertSuccessful();

        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $dnOrphanPsi->id]);
        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $cnOrphanPsi->id]);

        $this->assertDatabaseHas('payment_schedule_items', [
            'source_type' => DebitNoteLineItem::class,
            'source_id' => $dnNewLine->id,
            'amount' => 5_940_000,
        ]);
        $this->assertDatabaseHas('payment_schedule_items', [
            'source_type' => CreditNoteLineItem::class,
            'source_id' => $cnNewLine->id,
            'amount' => 2_500_000,
            'is_credit' => 1,
        ]);
    }

    public function test_cleans_schedule_items_of_cancelled_notes(): void
    {
        [$debitNote, $dnOrphanPsi] = $this->debitNoteWithLegacyEditDamage();
        $debitNote->update(['status' => DebitNoteStatus::CANCELLED]);

        $this->artisan('financial:repair-note-schedule-items')
            ->assertSuccessful();

        // Cancelled note: orphan removed AND no PSI created for its lines.
        $this->assertDatabaseMissing('payment_schedule_items', ['id' => $dnOrphanPsi->id]);
        $this->assertSame(0, PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $debitNote->id)->count());
    }

    public function test_dry_run_reports_without_changing(): void
    {
        [, $dnOrphanPsi, $dnNewLine] = $this->debitNoteWithLegacyEditDamage();

        $this->artisan('financial:repair-note-schedule-items --dry-run')
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertDatabaseHas('payment_schedule_items', ['id' => $dnOrphanPsi->id]);
        $this->assertDatabaseMissing('payment_schedule_items', [
            'source_type' => DebitNoteLineItem::class,
            'source_id' => $dnNewLine->id,
        ]);
    }

    public function test_reports_nothing_to_do_when_clean(): void
    {
        $this->artisan('financial:repair-note-schedule-items')
            ->expectsOutputToContain('Nenhum')
            ->assertSuccessful();
    }

    /**
     * Issued DN whose line was delete-recreated by the legacy edit page:
     * the issued PSI is orphaned and the new line has no PSI.
     *
     * @return array{DebitNote, PaymentScheduleItem, DebitNoteLineItem}
     */
    private function debitNoteWithLegacyEditDamage(): array
    {
        $debitNote = DebitNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => 60_121_500,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);
        $oldLine = DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'description' => '5% discount',
            'amount' => 60_121_500,
            'currency_code' => 'USD',
        ]);
        app(IssueDebitNoteAction::class)->execute($debitNote);

        $orphanPsi = PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
            ->where('source_id', $oldLine->id)
            ->firstOrFail();

        $oldLine->delete();
        $newLine = DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'description' => 'embroidered logo',
            'amount' => 5_940_000,
            'currency_code' => 'USD',
        ]);
        $debitNote->update(['total_amount' => 5_940_000]);

        return [$debitNote->refresh(), $orphanPsi, $newLine];
    }

    /**
     * @return array{CreditNote, PaymentScheduleItem, CreditNoteLineItem}
     */
    private function creditNoteWithLegacyEditDamage(): array
    {
        $creditNote = CreditNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => 3_000_000,
            'currency_code' => 'USD',
            'status' => CreditNoteStatus::DRAFT,
        ]);
        $oldLine = CreditNoteLineItem::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Customization Fee',
            'amount' => 3_000_000,
            'currency_code' => 'USD',
        ]);
        app(IssueCreditNoteAction::class)->execute($creditNote);

        $orphanPsi = PaymentScheduleItem::where('source_type', CreditNoteLineItem::class)
            ->where('source_id', $oldLine->id)
            ->firstOrFail();

        $oldLine->delete();
        $newLine = CreditNoteLineItem::create([
            'credit_note_id' => $creditNote->id,
            'description' => 'Rework deduction',
            'amount' => 2_500_000,
            'currency_code' => 'USD',
        ]);
        $creditNote->update(['total_amount' => 2_500_000]);

        return [$creditNote->refresh(), $orphanPsi, $newLine];
    }
}
