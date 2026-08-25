<?php

namespace Tests\Feature\CRM;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\Pages\EditCompany;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * As quatro colunas de nomenclatura de documentos (Task final) são
 * anuláveis por natureza: NULL significa "não configurado — herda da
 * matriz e, na ausência dela, cai no padrão histórico do sistema"
 * (NamingPreference::fromCompany()). Salvar o formulário sem tocar nesses
 * campos NUNCA pode gravar um valor explícito no lugar de NULL — isso
 * quebraria silenciosamente a herança de toda filial cujo cadastro seja
 * reaberto e salvo.
 */
class CompanyDocumentNamingDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create();
        Gate::before(fn (User $u) => $u->id === $admin->id ? true : null);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
    }

    private function makeCompany(array $attributes = []): Company
    {
        // Company::create() em vez de factory(): a factory sorteia campos
        // (telefone etc.) via Faker que podem falhar a validação ->tel() de
        // forma intermitente — ruído que nada tem a ver com nomenclatura.
        return Company::create(array_merge([
            'name' => 'Naming Defaults Co '.uniqid(),
            'status' => 'active',
        ], $attributes));
    }

    public function test_saving_with_all_four_naming_fields_blank_keeps_them_null_in_the_database(): void
    {
        $company = $this->makeCompany([
            'document_code_source' => DocumentNamingSource::SYSTEM,
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_description_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);
        $company->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm([
                'document_code_source' => null,
                'document_name_source' => null,
                'document_description_source' => null,
                'document_show_description' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();

        $this->assertNull($company->getRawOriginal('document_code_source'));
        $this->assertNull($company->getRawOriginal('document_name_source'));
        $this->assertNull($company->getRawOriginal('document_description_source'));
        $this->assertNull($company->getRawOriginal('document_show_description'));
    }

    public function test_saving_with_explicit_values_persists_them_and_they_survive_a_form_round_trip(): void
    {
        $company = $this->makeCompany();
        $company->companyRoles()->create(['role' => CompanyRole::CLIENT->value]);

        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->fillForm([
                'document_code_source' => DocumentNamingSource::SYSTEM->value,
                'document_name_source' => DocumentNamingSource::SYSTEM->value,
                'document_description_source' => DocumentNamingSource::COUNTERPARTY->value,
                'document_show_description' => '0',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();

        $this->assertSame(DocumentNamingSource::SYSTEM, $company->document_code_source);
        $this->assertSame(DocumentNamingSource::SYSTEM, $company->document_name_source);
        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $company->document_description_source);
        $this->assertFalse($company->document_show_description);

        // Round trip: reopening the form must show the persisted values, not
        // the "inherit" placeholder.
        Livewire::test(EditCompany::class, ['record' => $company->getRouteKey()])
            ->assertFormSet([
                'document_code_source' => DocumentNamingSource::SYSTEM,
                'document_name_source' => DocumentNamingSource::SYSTEM,
                'document_description_source' => DocumentNamingSource::COUNTERPARTY,
                'document_show_description' => '0',
            ]);
    }

    public function test_branch_left_blank_still_resolves_to_its_headquarters_values(): void
    {
        $headquarters = $this->makeCompany([
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);
        $branch = $this->makeCompany([
            'parent_company_id' => $headquarters->id,
            'document_code_source' => null,
            'document_name_source' => null,
            'document_description_source' => null,
            'document_show_description' => null,
        ]);

        $preference = NamingPreference::fromCompany($branch, $headquarters);

        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->name);
        $this->assertFalse($preference->showDescription);
        // Fields neither side configured still fall back to the historical default.
        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->code);
    }
}
