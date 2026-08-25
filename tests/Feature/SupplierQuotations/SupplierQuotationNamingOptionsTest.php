<?php

namespace Tests\Feature\SupplierQuotations;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Filament\Resources\SupplierQuotations\Pages\ViewSupplierQuotation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Controles de nomenclatura no modal de geração da RFQ (Task 10) — mesma
 * seção compartilhada (HasDocumentNamingOptions) que o Shipment já tinha
 * (Task 8). Espelha
 * tests/Feature/Shipments/CommercialInvoiceNamingOptionsTest.php.
 */
class SupplierQuotationNamingOptionsTest extends TestCase
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
        $sq = SupplierQuotation::factory()->create();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('generatePdf')
            ->assertFormFieldExists(NamingPreference::KEY_CODE)
            ->assertFormFieldExists(NamingPreference::KEY_NAME)
            ->assertFormFieldExists(NamingPreference::KEY_SHOW_DESCRIPTION)
            ->assertFormFieldExists(NamingPreference::KEY_DESCRIPTION);
    }

    /**
     * Os defaults têm que vir da preferência RESOLVIDA do fornecedor
     * (NamingPreference::fromCompany($record?->company)), nunca das colunas
     * cruas — sem parent: porque fornecedor não tem filial.
     */
    public function test_defaults_reflect_the_suppliers_configured_naming_preference_not_the_historical_default(): void
    {
        $supplier = Company::factory()->create([
            'document_code_source' => DocumentNamingSource::SYSTEM,
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_description_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);
        $sq = SupplierQuotation::factory()->create(['company_id' => $supplier->id]);

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
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
        $sq = SupplierQuotation::factory()->create();

        Livewire::test(ViewSupplierQuotation::class, ['record' => $sq->getKey()])
            ->mountAction('generatePdf')
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => false])
            ->assertFormFieldHidden(NamingPreference::KEY_DESCRIPTION)
            ->fillForm([NamingPreference::KEY_SHOW_DESCRIPTION => true])
            ->assertFormFieldVisible(NamingPreference::KEY_DESCRIPTION);
    }
}
