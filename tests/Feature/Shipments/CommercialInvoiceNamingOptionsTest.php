<?php

namespace Tests\Feature\Shipments;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Resources\Shipments\Pages\ViewShipment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Controles de nomenclatura no modal de geração de documentos do embarque
 * (Task 8). Cobre a Commercial Invoice PDF; o formulário é compartilhado
 * pelas outras cinco actions (preview, e-mail, Excel, proforma) então uma
 * cobertura aqui já vale para as seis.
 */
class CommercialInvoiceNamingOptionsTest extends TestCase
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
        $shipment = Shipment::factory()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('generateCommercialInvoicePdf')
            ->assertFormFieldExists(NamingPreference::KEY_CODE)
            ->assertFormFieldExists(NamingPreference::KEY_NAME)
            ->assertFormFieldExists(NamingPreference::KEY_SHOW_DESCRIPTION)
            ->assertFormFieldExists(NamingPreference::KEY_DESCRIPTION);
    }

    public function test_defaults_reflect_the_companys_configured_naming_preference_not_the_historical_default(): void
    {
        $client = Company::factory()->create([
            'document_code_source' => DocumentNamingSource::SYSTEM,
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_description_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);
        $shipment = Shipment::factory()->create(['company_id' => $client->id]);

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('generateCommercialInvoicePdf')
            ->assertActionDataSet([
                NamingPreference::KEY_CODE => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_NAME => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_SHOW_DESCRIPTION => false,
            ]);
    }

    /**
     * O caso central desta unidade: a filial não tem nada configurado nas
     * próprias colunas (NULL = "não configurado"), mas o embarque é
     * endereçado à matriz que escolheu SYSTEM. Ler a coluna crua da filial
     * mostraria "Contraparte" (o default histórico do enum ausente) enquanto
     * o documento de fato sai com a nomenclatura do sistema — é exatamente a
     * mentira que fromCompany() existe para evitar.
     */
    public function test_branch_with_blank_columns_inherits_system_from_its_headquarters(): void
    {
        $headquarters = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);
        $branch = Company::factory()->create([
            'document_code_source' => null,
            'document_name_source' => null,
            'document_description_source' => null,
            'document_show_description' => null,
        ]);
        $shipment = Shipment::factory()->create([
            'company_id' => $headquarters->id,
            'company_branch_id' => $branch->id,
        ]);

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('generateCommercialInvoicePdf')
            ->assertActionDataSet([
                NamingPreference::KEY_NAME => DocumentNamingSource::SYSTEM,
            ]);
    }

    public function test_description_source_control_is_hidden_when_the_show_description_toggle_is_off(): void
    {
        $shipment = Shipment::factory()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('generateCommercialInvoicePdf')
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => false])
            ->assertFormFieldHidden(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => true])
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION);
    }
}
