<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\CRM\Models\Company;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\Settings\Models\Currency;
use App\Filament\Resources\ProformaInvoices\Pages\CreateProformaInvoice;
use App\Filament\Resources\ProformaInvoices\Pages\EditProformaInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Create Proforma Invoice: o cliente vem antes da Inquiry, a Inquiry só lista
 * as do cliente escolhido e é encontrada também pela descrição — antes o
 * campo mostrava só "nº do processo — empresa", de todos os clientes.
 */
class ProformaInvoiceInquiryFieldTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Company $otherClient;

    private Inquiry $slats;

    private Inquiry $chains;

    private Inquiry $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $user = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $user->id ? true : null);
        $this->actingAs($user);

        foreach (['USD' => 'US Dollar', 'EUR' => 'Euro'] as $code => $name) {
            Currency::create(['code' => $code, 'name' => $name, 'name_plural' => $name.'s', 'symbol' => $code, 'decimal_places' => 2, 'is_active' => true]);
        }

        $this->client = Company::factory()->create(['name' => 'Alpha Client']);
        $this->client->companyRoles()->create(['role' => 'client']);
        $this->otherClient = Company::factory()->create(['name' => 'Beta Client']);
        $this->otherClient->companyRoles()->create(['role' => 'client']);

        $this->slats = Inquiry::factory()->create([
            'company_id' => $this->client->id,
            'reference' => 'INQ-2026-09001',
            'description' => 'Slats quotation',
            'currency_code' => 'EUR',
        ]);
        $this->chains = Inquiry::factory()->create([
            'company_id' => $this->client->id,
            'reference' => 'INQ-2026-09002',
            'description' => null,
        ]);
        $this->foreign = Inquiry::factory()->create([
            'company_id' => $this->otherClient->id,
            'reference' => 'INQ-2026-09003',
            'description' => 'Slats for another client',
        ]);
    }

    public function test_client_comes_before_the_inquiry_on_the_form(): void
    {
        Livewire::test(CreateProformaInvoice::class)
            ->assertSeeHtmlInOrder(['data.company_id', 'data.inquiry_id', 'data.contact_id', 'data.client_reference']);
    }

    public function test_without_a_client_the_inquiry_field_offers_nothing(): void
    {
        Livewire::test(CreateProformaInvoice::class)
            ->assertFormFieldExists('inquiry_id', fn (Select $select): bool => $select->getOptions() === []
                && $select->getSearchResults('INQ') === []
                && $select->getPlaceholder() === __('forms.placeholders.select_the_client_first'));
    }

    public function test_inquiry_field_lists_only_the_inquiries_of_the_chosen_client(): void
    {
        Livewire::test(CreateProformaInvoice::class)
            ->fillForm(['company_id' => $this->client->id])
            ->assertFormFieldExists('inquiry_id', function (Select $select): bool {
                return $select->getOptions() === [
                    // Mais recente primeiro; sem descrição, cai no nome da empresa.
                    $this->chains->id => 'INQ-2026-09002 — Alpha Client',
                    $this->slats->id => 'INQ-2026-09001 — Slats quotation',
                ];
            });
    }

    public function test_inquiry_is_found_by_its_description_within_the_client(): void
    {
        Livewire::test(CreateProformaInvoice::class)
            ->fillForm(['company_id' => $this->client->id])
            ->assertFormFieldExists('inquiry_id', function (Select $select): bool {
                return array_keys($select->getSearchResults('slats')) === [$this->slats->id]
                    && array_keys($select->getSearchResults('09002')) === [$this->chains->id]
                    // A de outro cliente não aparece nem pelo número exato.
                    && $select->getSearchResults('INQ-2026-09003') === [];
            });
    }

    public function test_changing_the_client_drops_an_inquiry_that_belongs_to_another_client(): void
    {
        Livewire::test(CreateProformaInvoice::class)
            ->fillForm(['company_id' => $this->client->id])
            ->fillForm(['inquiry_id' => $this->slats->id])
            ->assertFormSet(['inquiry_id' => $this->slats->id, 'currency_code' => 'EUR'])
            ->fillForm(['company_id' => $this->otherClient->id])
            ->assertFormSet(['inquiry_id' => null, 'contact_id' => null]);
    }

    public function test_pi_is_created_with_client_then_inquiry(): void
    {
        Livewire::test(CreateProformaInvoice::class)
            ->fillForm(['company_id' => $this->client->id])
            ->fillForm(['inquiry_id' => $this->slats->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $pi = ProformaInvoice::query()->latest('id')->firstOrFail();
        $this->assertSame($this->client->id, $pi->company_id);
        $this->assertSame($this->slats->id, $pi->inquiry_id);
        $this->assertSame('EUR', $pi->currency_code);
    }

    public function test_editing_keeps_the_existing_inquiry_and_its_label(): void
    {
        $pi = ProformaInvoice::factory()->create([
            'company_id' => $this->client->id,
            'inquiry_id' => $this->slats->id,
        ]);

        Livewire::test(EditProformaInvoice::class, ['record' => $pi->getRouteKey()])
            ->assertFormSet(['inquiry_id' => $this->slats->id])
            ->assertFormFieldExists('inquiry_id', fn (Select $select): bool => $select->getOptionLabel() === 'INQ-2026-09001 — Slats quotation')
            ->call('save')
            ->assertHasNoFormErrors();
    }
}
