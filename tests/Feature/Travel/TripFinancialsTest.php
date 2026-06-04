<?php

namespace Tests\Feature\Travel;

use App\Domain\CRM\Models\Company;
use App\Domain\Finance\Enums\ExpenseApprovalStatus;
use App\Domain\Finance\Enums\ExpenseCategory;
use App\Domain\Finance\Models\CompanyExpense;
use App\Domain\Financial\Enums\DebitNoteStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Travel\Actions\ApproveTripAction;
use App\Domain\Travel\DataTransferObjects\TripBillingData;
use App\Domain\Travel\Enums\TravelExpenseCategory;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use App\Domain\Travel\Support\TripExpenseReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TripFinancialsTest extends TestCase
{
    use RefreshDatabase;

    private ApproveTripAction $action;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(ApproveTripAction::class);
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function client(): Company
    {
        $company = Company::create(['name' => 'Client Co', 'status' => 'active']);
        $company->companyRoles()->create(['role' => 'client']);

        return $company;
    }

    private function tripWith(array $attributes, array $expenses): Trip
    {
        $trip = Trip::create(array_merge([
            'title' => 'Trip',
            'user_id' => $this->user->id,
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
            'status' => TripStatus::SUBMITTED,
        ], $attributes));

        foreach ($expenses as $e) {
            $trip->expenses()->create([
                'category' => $e['category'] ?? TravelExpenseCategory::MEALS->value,
                'description' => $e['description'] ?? null,
                'amount' => $e['amount'],
                'currency_code' => $e['currency'],
                'expense_date' => $e['date'] ?? '2026-06-02 12:30',
            ]);
        }

        return $trip;
    }

    public function test_internal_trip_creates_approved_company_expense_per_currency(): void
    {
        $trip = $this->tripWith(['is_internal' => true], [
            ['amount' => Money::toMinor(100), 'currency' => 'CNY'],
            ['amount' => Money::toMinor(50), 'currency' => 'CNY'],
            ['amount' => Money::toMinor(20), 'currency' => 'USD'],
        ]);

        $this->action->approve($trip);

        $this->assertSame(2, CompanyExpense::count());

        $cny = CompanyExpense::where('reference', "TRIP-{$trip->id}-CNY")->first();
        $this->assertNotNull($cny);
        $this->assertSame(Money::toMinor(150), $cny->amount);
        $this->assertSame(ExpenseCategory::TRAVEL, $cny->category);
        $this->assertSame(ExpenseApprovalStatus::APPROVED, $cny->status);
        $this->assertSame($this->user->id, $cny->approved_by);

        $usd = CompanyExpense::where('reference', "TRIP-{$trip->id}-USD")->first();
        $this->assertSame(Money::toMinor(20), $usd->amount);
    }

    public function test_client_trip_creates_single_draft_debit_note_in_billing_currency(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['category' => TravelExpenseCategory::LODGING->value, 'amount' => Money::toMinor(300), 'currency' => 'CNY'],
            ['category' => TravelExpenseCategory::MEALS->value, 'amount' => Money::toMinor(80), 'currency' => 'CNY'],
            ['category' => TravelExpenseCategory::TRANSPORT->value, 'amount' => Money::toMinor(40), 'currency' => 'USD'],
        ]);

        // Default billing (no currencies seeded → USD, rate 1.0): a single note
        // itemised per expense.
        $this->action->approve($trip);

        $this->assertSame(1, DebitNote::where('trip_id', $trip->id)->count());
        $dn = DebitNote::where('trip_id', $trip->id)->first();
        $this->assertSame($client->id, $dn->company_id);
        $this->assertSame('USD', $dn->currency_code);
        $this->assertSame(DebitNoteStatus::DRAFT, $dn->status);
        $this->assertSame(Money::toMinor(420), $dn->total_amount);
        $this->assertSame(3, $dn->lineItems()->count());
    }

    public function test_debit_note_uses_supplied_billing_currency_and_rate(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['amount' => Money::toMinor(1000), 'currency' => 'CNY'],
        ]);

        $this->action->approve($trip, new TripBillingData('USD', ['CNY' => 0.14]));

        $dn = DebitNote::where('trip_id', $trip->id)->first();
        $this->assertSame('USD', $dn->currency_code);
        $this->assertSame(Money::toMinor(140), $dn->total_amount); // 1000 CNY * 0.14
    }

    public function test_approval_is_idempotent_and_does_not_double_post(): void
    {
        $trip = $this->tripWith(['is_internal' => true], [
            ['amount' => Money::toMinor(100), 'currency' => 'CNY'],
        ]);

        $this->action->approve($trip);
        // Second call (guard: already approved) must not create a second expense.
        $this->action->approve($trip->refresh());

        $this->assertSame(1, CompanyExpense::count());
    }

    public function test_editing_expense_reverts_approval_and_reapproval_updates_same_debit_note(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['amount' => Money::toMinor(100), 'currency' => 'CNY'],
        ]);

        $this->action->approve($trip);
        $dn = DebitNote::where('trip_id', $trip->id)->first();
        $this->assertSame(Money::toMinor(100), $dn->total_amount);
        $this->assertSame(1, $dn->lineItems()->count());
        $debitNoteId = $dn->id;

        // A mistake is fixed: add a missing expense. This must reopen the trip.
        $trip->expenses()->create([
            'category' => TravelExpenseCategory::LODGING->value,
            'amount' => Money::toMinor(50),
            'currency_code' => 'CNY',
            'expense_date' => '2026-06-02 22:00',
        ]);
        $this->assertSame(TripStatus::SUBMITTED, $trip->refresh()->status);

        // Re-approve: same debit note, refreshed total + items — not duplicated.
        $this->action->approve($trip);

        $this->assertSame(1, DebitNote::where('trip_id', $trip->id)->count());
        $dn->refresh();
        $this->assertSame($debitNoteId, $dn->id);
        $this->assertSame(Money::toMinor(150), $dn->total_amount);
        $this->assertSame(2, $dn->lineItems()->count());
    }

    public function test_editing_header_of_approved_trip_reverts_to_draft(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['amount' => Money::toMinor(100), 'currency' => 'CNY'],
        ]);
        $this->action->approve($trip);
        $this->assertSame(TripStatus::APPROVED, $trip->refresh()->status);

        $trip->update(['title' => 'Título corrigido']);

        $this->assertSame(TripStatus::SUBMITTED, $trip->refresh()->status);
        $this->assertNull($trip->approved_at);
    }

    public function test_issued_debit_note_is_not_overwritten_on_reapproval(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['amount' => Money::toMinor(100), 'currency' => 'CNY'],
        ]);
        $this->action->approve($trip);
        $dn = DebitNote::where('trip_id', $trip->id)->first();
        $dn->update(['status' => \App\Domain\Financial\Enums\DebitNoteStatus::ISSUED]);

        // Reopen + re-approve.
        $trip->expenses()->create([
            'category' => TravelExpenseCategory::MEALS->value,
            'amount' => Money::toMinor(50),
            'currency_code' => 'CNY',
            'expense_date' => '2026-06-02 13:00',
        ]);
        $this->action->approve($trip->refresh());

        // The issued note is untouched (still 100, 1 item) to protect billing.
        $dn->refresh();
        $this->assertSame(Money::toMinor(100), $dn->total_amount);
        $this->assertSame(1, $dn->lineItems()->count());
    }

    public function test_expense_report_converts_to_target_currency(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['category' => TravelExpenseCategory::LODGING->value, 'amount' => Money::toMinor(1000), 'currency' => 'CNY'],
        ]);

        $data = TripExpenseReport::build($trip, new TripBillingData('USD', ['CNY' => 0.14]));

        $this->assertSame('USD', $data['target_currency']);
        $this->assertCount(1, $data['rows']);
        $this->assertSame('CNY', $data['rows'][0]['source_currency']);
        $this->assertSame('140.00', $data['rows'][0]['target_amount']);
        $this->assertSame('140.00', $data['grand_total_target']);
        // The FX rate is shown in the header, not as a column.
        $this->assertContains('CNY → USD @ 0.14', $data['fx_rates']);
        // Company header (name used in logo/footer) is present.
        $this->assertArrayHasKey('company', $data);
        $this->assertNotEmpty($data['company']['name']);
    }

    public function test_report_renders_category_labels_in_the_chosen_language(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['category' => TravelExpenseCategory::LODGING->value, 'amount' => Money::toMinor(100), 'currency' => 'CNY'],
        ]);

        app()->setLocale('en');
        $en = TripExpenseReport::build($trip, new TripBillingData('CNY', []));
        $this->assertSame('Lodging', $en['rows'][0]['category']);

        app()->setLocale('pt_BR');
        $pt = TripExpenseReport::build($trip, new TripBillingData('CNY', []));
        $this->assertSame('Hospedagem', $pt['rows'][0]['category']);
    }

    public function test_expense_report_embeds_receipt_images_when_present(): void
    {
        Storage::fake('public');
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['amount' => Money::toMinor(100), 'currency' => 'CNY'],
        ]);
        $expense = $trip->expenses->first();
        $path = UploadedFile::fake()->image('receipt.jpg')->store('trip-receipts', 'public');
        $expense->photos()->create(['disk' => 'public', 'path' => $path, 'sort_order' => 0, 'is_primary' => true]);

        $data = TripExpenseReport::build($trip->refresh(), new TripBillingData('CNY', []));

        // Receipt is embedded inline on its expense row (a column), as a data URI.
        $this->assertCount(1, $data['rows'][0]['receipts']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $data['rows'][0]['receipts'][0]);
    }

    public function test_rejection_creates_no_financial_entries(): void
    {
        $client = $this->client();
        $trip = $this->tripWith(['company_id' => $client->id], [
            ['amount' => Money::toMinor(100), 'currency' => 'CNY'],
        ]);

        $this->action->reject($trip, 'Comprovantes faltando');

        $this->assertSame(0, DebitNote::count());
        $this->assertSame(0, CompanyExpense::count());
        $this->assertSame(TripStatus::REJECTED, $trip->refresh()->status);
    }
}
