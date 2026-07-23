<?php

namespace Tests\Feature\Financial;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Actions\IssueCreditNoteAction;
use App\Domain\Financial\Actions\IssueDebitNoteAction;
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
use App\Domain\Settings\Models\Currency;
use App\Filament\Resources\Finance\CreditNotes\Pages\EditCreditNote;
use App\Filament\Resources\Finance\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\Finance\DebitNotes\Pages\EditDebitNote;
use App\Filament\Resources\Finance\DebitNotes\Pages\ViewDebitNote;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class EditNotePreservesScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::create(['name' => 'Factory Co', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => CompanyRole::SUPPLIER->value]);

        Currency::create([
            'code' => 'USD',
            'name' => 'US Dollar',
            'name_plural' => 'US Dollars',
            'symbol' => '$',
            'decimal_places' => 2,
            'is_base' => true,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel('admin');
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
    }

    public function test_editing_issued_debit_note_updates_line_in_place_and_syncs_schedule(): void
    {
        $debitNote = $this->issuedDebitNote('5% discount', 60_121_500);
        $line = $debitNote->lineItems()->firstOrFail();

        $component = Livewire::test(EditDebitNote::class, ['record' => $debitNote->getRouteKey()]);

        // Repeater rows are uuid-keyed; edit the existing row in place the way
        // the browser does (fillForm would append a second row instead).
        $items = $component->get('data.line_items');
        $this->assertCount(1, $items);
        $rowKey = array_key_first($items);

        $component
            ->set("data.line_items.{$rowKey}.description", 'embroidered logo')
            ->set("data.line_items.{$rowKey}.amount", 594.00)
            ->call('save')
            ->assertHasNoFormErrors();

        // Line updated in place — same id, no delete-recreate.
        $this->assertDatabaseHas('debit_note_line_items', [
            'id' => $line->id,
            'description' => 'embroidered logo',
            'amount' => 5_940_000,
        ]);
        $this->assertSame(1, $debitNote->lineItems()->count());

        // The issued PSI follows the line instead of going stale.
        $psi = PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
            ->where('source_id', $line->id)
            ->firstOrFail();
        $this->assertSame(5_940_000, $psi->amount);
        $this->assertStringContainsString('embroidered logo', $psi->label);

        // No orphaned PSIs left behind.
        $this->assertSame(1, PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $debitNote->id)->count());

        $this->assertSame(5_940_000, $debitNote->refresh()->total_amount);
    }

    public function test_adding_line_to_issued_debit_note_creates_schedule_item(): void
    {
        $debitNote = $this->issuedDebitNote('Tooling', 1_000_000);
        $line = $debitNote->lineItems()->firstOrFail();

        $component = Livewire::test(EditDebitNote::class, ['record' => $debitNote->getRouteKey()]);

        $items = $component->get('data.line_items');
        $items['new-row'] = ['id' => null, 'description' => 'Logo', 'amount' => 50.00, 'currency_code' => 'USD'];

        $component
            ->set('data.line_items', $items)
            ->call('save')
            ->assertHasNoFormErrors();

        $newLine = $debitNote->lineItems()->where('description', 'Logo')->firstOrFail();
        $this->assertDatabaseHas('payment_schedule_items', [
            'source_type' => DebitNoteLineItem::class,
            'source_id' => $newLine->id,
            'amount' => 500_000,
        ]);
    }

    public function test_removing_allocated_line_is_blocked(): void
    {
        $debitNote = $this->issuedDebitNote('Tooling', 1_000_000);
        $line = $debitNote->lineItems()->firstOrFail();
        $psi = PaymentScheduleItem::where('source_type', DebitNoteLineItem::class)
            ->where('source_id', $line->id)
            ->firstOrFail();

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

        Livewire::test(EditDebitNote::class, ['record' => $debitNote->getRouteKey()])
            ->set('data.line_items', [
                'new-row' => ['id' => null, 'description' => 'Other charge', 'amount' => 10.00, 'currency_code' => 'USD'],
            ])
            ->call('save')
            ->assertHasErrors();

        // Nothing changed: line and PSI intact.
        $this->assertDatabaseHas('debit_note_line_items', ['id' => $line->id]);
        $this->assertDatabaseHas('payment_schedule_items', ['id' => $psi->id, 'amount' => 1_000_000]);
    }

    public function test_editing_issued_credit_note_updates_credit_schedule_item(): void
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
        $line = $creditNote->lineItems()->firstOrFail();

        $component = Livewire::test(EditCreditNote::class, ['record' => $creditNote->getRouteKey()]);

        $items = $component->get('data.line_items');
        $rowKey = array_key_first($items);

        $component
            ->set("data.line_items.{$rowKey}.description", 'Rework deduction')
            ->set("data.line_items.{$rowKey}.amount", 250.00)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, $creditNote->lineItems()->count());

        $psi = PaymentScheduleItem::where('source_type', CreditNoteLineItem::class)
            ->where('source_id', $line->id)
            ->firstOrFail();
        $this->assertSame(2_500_000, $psi->amount);
        $this->assertTrue((bool) $psi->is_credit);
        $this->assertSame(2_500_000, $creditNote->refresh()->total_amount);
    }

    public function test_cancelling_issued_debit_note_removes_its_schedule_items(): void
    {
        $debitNote = $this->issuedDebitNote('Tooling', 1_000_000);

        Livewire::test(ViewDebitNote::class, ['record' => $debitNote->getRouteKey()])
            ->callAction('cancel');

        $this->assertSame(DebitNoteStatus::CANCELLED, $debitNote->refresh()->status);
        $this->assertSame(0, PaymentScheduleItem::where('payable_type', DebitNote::class)
            ->where('payable_id', $debitNote->id)->count());
    }

    public function test_cancelling_issued_credit_note_removes_its_credit_schedule_items(): void
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

        Livewire::test(ViewCreditNote::class, ['record' => $creditNote->getRouteKey()])
            ->callAction('cancel');

        $this->assertSame(CreditNoteStatus::CANCELLED, $creditNote->refresh()->status);
        $this->assertSame(0, PaymentScheduleItem::where('payable_type', CreditNote::class)
            ->where('payable_id', $creditNote->id)->count());
    }

    private function issuedDebitNote(string $description, int $amount): DebitNote
    {
        $debitNote = DebitNote::create([
            'company_id' => $this->supplier->id,
            'party_type' => PartyType::SUPPLIER,
            'total_amount' => $amount,
            'currency_code' => 'USD',
            'status' => DebitNoteStatus::DRAFT,
        ]);

        DebitNoteLineItem::create([
            'debit_note_id' => $debitNote->id,
            'description' => $description,
            'amount' => $amount,
            'currency_code' => 'USD',
        ]);

        app(IssueDebitNoteAction::class)->execute($debitNote);

        return $debitNote->refresh();
    }
}
