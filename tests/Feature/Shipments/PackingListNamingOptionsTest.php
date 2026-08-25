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
 * Gap de honestidade 1 (revisão final do feat/nomenclatura-documentos): as
 * três ações do Packing List (generate PDF, preview PDF, export Excel) não
 * tinham formSchema NENHUM — surdas ao toggle mesmo depois do resolver
 * honrar a preferência (05dbd053). Cobre as três; espelha
 * CommercialInvoiceNamingOptionsTest.php.
 */
class PackingListNamingOptionsTest extends TestCase
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

    public function test_generate_pdf_has_naming_controls_with_the_naming_preference_constants_as_field_names(): void
    {
        $shipment = Shipment::factory()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('generatePackingListPdf')
            ->assertFormFieldExists(NamingPreference::KEY_CODE)
            ->assertFormFieldExists(NamingPreference::KEY_NAME)
            ->assertFormFieldExists(NamingPreference::KEY_SHOW_DESCRIPTION)
            ->assertFormFieldExists(NamingPreference::KEY_DESCRIPTION);
    }

    public function test_preview_pdf_has_naming_controls_with_the_naming_preference_constants_as_field_names(): void
    {
        $shipment = Shipment::factory()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('previewPackingListPdf')
            ->assertFormFieldExists(NamingPreference::KEY_CODE)
            ->assertFormFieldExists(NamingPreference::KEY_NAME)
            ->assertFormFieldExists(NamingPreference::KEY_SHOW_DESCRIPTION)
            ->assertFormFieldExists(NamingPreference::KEY_DESCRIPTION);
    }

    public function test_export_excel_has_naming_controls_with_the_naming_preference_constants_as_field_names(): void
    {
        $shipment = Shipment::factory()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('exportPackingListExcel')
            ->assertFormFieldExists(NamingPreference::KEY_CODE)
            ->assertFormFieldExists(NamingPreference::KEY_NAME)
            ->assertFormFieldExists(NamingPreference::KEY_SHOW_DESCRIPTION)
            ->assertFormFieldExists(NamingPreference::KEY_DESCRIPTION);
    }

    /**
     * Defaults têm que vir de getDocumentClient()+company — mesmo
     * namingPreferenceDefaults() que a Commercial Invoice já usa, porque as
     * duas nascem do mesmo shipment.
     */
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
            ->mountAction('generatePackingListPdf')
            ->assertActionDataSet([
                NamingPreference::KEY_CODE => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_NAME => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
                NamingPreference::KEY_SHOW_DESCRIPTION => false,
            ]);
    }

    public function test_description_source_control_is_hidden_when_the_show_description_toggle_is_off(): void
    {
        $shipment = Shipment::factory()->create();

        Livewire::test(ViewShipment::class, ['record' => $shipment->getKey()])
            ->mountAction('generatePackingListPdf')
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => false])
            ->assertFormFieldHidden(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => true])
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION);
    }
}
