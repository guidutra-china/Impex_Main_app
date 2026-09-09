<?php

namespace Tests\Feature\Travel;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Travel\Actions\ApproveTripAction;
use App\Domain\Travel\Enums\TravelExpenseCategory;
use App\Domain\Travel\Enums\TripStatus;
use App\Domain\Travel\Models\Trip;
use App\Filament\Resources\Finance\Trips\Pages\CreateTrip;
use App\Filament\Resources\ProformaInvoices\Pages\ViewProformaInvoice;
use App\Filament\Resources\ProformaInvoices\RelationManagers\TripsRelationManager;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Uma viagem de cliente pode apontar para UMA PI do mesmo cliente (decisão
 * 2026-09-09: rastreio, não cobrança). A nota de débito gerada na aprovação
 * carrega a PI, e a PI lista suas viagens.
 */
class TripProformaInvoiceLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $client;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $this->user->id ? true : null);
        $this->actingAs($this->user);
        Filament::setCurrentPanel('admin');

        $this->client = $this->client('Client Co');
        $this->pi = $this->piFor($this->client, 'PI-LINK-1');
    }

    private function client(string $name): Company
    {
        $company = Company::create(['name' => $name, 'status' => 'active']);
        $company->companyRoles()->create(['role' => 'client']);

        return $company;
    }

    private function piFor(Company $client, string $reference): ProformaInvoice
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-'.$reference,
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        return ProformaInvoice::create([
            'reference' => $reference,
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-09-01',
            'status' => 'confirmed',
        ]);
    }

    private function trip(array $attributes = []): Trip
    {
        return Trip::create(array_merge([
            'title' => 'Factory visit',
            'user_id' => $this->user->id,
            'company_id' => $this->client->id,
            'start_date' => '2026-09-10',
            'status' => TripStatus::SUBMITTED,
        ], $attributes));
    }

    public function test_trip_links_to_a_pi_of_its_company_and_the_pi_lists_it(): void
    {
        $trip = $this->trip(['proforma_invoice_id' => $this->pi->id]);

        $this->assertTrue($trip->proformaInvoice->is($this->pi));
        $this->assertTrue($this->pi->trips()->whereKey($trip->id)->exists());
    }

    public function test_pi_from_another_company_is_rejected(): void
    {
        $otherPi = $this->piFor($this->client('Other Co'), 'PI-OTHER');

        $this->expectException(\InvalidArgumentException::class);

        $this->trip(['proforma_invoice_id' => $otherPi->id]);
    }

    public function test_internal_trip_drops_the_pi(): void
    {
        $trip = $this->trip(['is_internal' => true, 'company_id' => null, 'proforma_invoice_id' => $this->pi->id]);

        $this->assertNull($trip->fresh()->proforma_invoice_id);
    }

    public function test_changing_the_pi_of_an_approved_trip_reopens_it(): void
    {
        $trip = $this->trip(['status' => TripStatus::APPROVED, 'approved_by' => $this->user->id, 'approved_at' => now()]);

        $trip->update(['proforma_invoice_id' => $this->pi->id]);

        $this->assertSame(TripStatus::SUBMITTED, $trip->fresh()->status);
    }

    public function test_debit_note_generated_on_approval_carries_the_pi(): void
    {
        $trip = $this->trip(['proforma_invoice_id' => $this->pi->id]);
        $trip->expenses()->create([
            'category' => TravelExpenseCategory::MEALS->value,
            'amount' => Money::toMinor(80),
            'currency_code' => 'USD',
            'expense_date' => '2026-09-11 12:00',
        ]);

        app(ApproveTripAction::class)->approve($trip);

        $dn = DebitNote::where('trip_id', $trip->id)->first();
        $this->assertNotNull($dn);
        $this->assertSame($this->pi->id, $dn->proforma_invoice_id);
    }

    public function test_form_offers_only_pis_of_the_chosen_company_and_hides_it_for_internal_trips(): void
    {
        $this->piFor($this->client('Other Co'), 'PI-OTHER');

        Livewire::test(CreateTrip::class)
            ->fillForm(['is_internal' => false, 'company_id' => $this->client->id])
            ->assertFormFieldExists('proforma_invoice_id', fn (Select $select) => array_keys($select->getOptions()) === [$this->pi->id])
            ->fillForm(['is_internal' => true])
            ->assertFormFieldHidden('proforma_invoice_id');
    }

    public function test_pi_trips_tab_lists_the_linked_trip(): void
    {
        $linked = $this->trip(['proforma_invoice_id' => $this->pi->id]);
        $unlinked = $this->trip(['title' => 'Other trip']);

        Livewire::test(TripsRelationManager::class, [
            'ownerRecord' => $this->pi->fresh(),
            'pageClass' => ViewProformaInvoice::class,
        ])
            ->assertCanSeeTableRecords([$linked])
            ->assertCanNotSeeTableRecords([$unlinked]);
    }
}
