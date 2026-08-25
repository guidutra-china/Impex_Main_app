<?php

namespace Tests\Feature\ProformaInvoices;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Resources\ProformaInvoices\Pages\ViewProformaInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gap de honestidade 1 (revisão final do feat/nomenclatura-documentos): a PI
 * tinha formSchema para as outras opções (fotos, código, comissão) mas nunca
 * ganhou a seção de nomenclatura — mesmo depois do resolver honrar a
 * preferência (05dbd053). Espelha PurchaseOrderNamingOptionsTest.php.
 */
class ProformaInvoiceNamingOptionsTest extends TestCase
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

    public function test_naming_controls_exist_with_the_naming_preference_constants_as_field_names(): void
    {
        $pi = ProformaInvoice::factory()->create();

        Livewire::test(ViewProformaInvoice::class, ['record' => $pi->getKey()])
            ->mountAction('generatePdf')
            ->assertFormFieldExists(NamingPreference::KEY_CODE)
            ->assertFormFieldExists(NamingPreference::KEY_NAME)
            ->assertFormFieldExists(NamingPreference::KEY_SHOW_DESCRIPTION)
            ->assertFormFieldExists(NamingPreference::KEY_DESCRIPTION);
    }

    /**
     * Os defaults têm que vir da preferência RESOLVIDA da empresa
     * (NamingPreference::fromCompany($record?->company)), nunca das colunas
     * cruas — mesma lição do Shipment e do PO. PI não tem conceito de
     * filial, então sem parent:.
     */
    public function test_defaults_reflect_the_companys_configured_naming_preference_not_the_historical_default(): void
    {
        $client = Company::factory()->create([
            'document_code_source' => DocumentNamingSource::SYSTEM,
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_description_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);
        $pi = ProformaInvoice::factory()->create(['company_id' => $client->id]);

        Livewire::test(ViewProformaInvoice::class, ['record' => $pi->getKey()])
            ->mountAction('generatePdf')
            ->assertActionDataSet([
                NamingPreference::KEY_CODE => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_NAME => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_SHOW_DESCRIPTION => false,
            ]);
    }

    public function test_description_source_control_is_hidden_when_the_show_description_toggle_is_off(): void
    {
        $pi = ProformaInvoice::factory()->create();

        Livewire::test(ViewProformaInvoice::class, ['record' => $pi->getKey()])
            ->mountAction('generatePdf')
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => false])
            ->assertFormFieldHidden(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => true])
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION);
    }
}
