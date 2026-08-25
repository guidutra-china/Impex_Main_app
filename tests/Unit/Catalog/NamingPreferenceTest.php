<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_preserva_o_comportamento_historico(): void
    {
        $preference = NamingPreference::default();

        $this->assertSame(DocumentNamingSource::Counterparty, $preference->code);
        $this->assertSame(DocumentNamingSource::Counterparty, $preference->name);
        $this->assertSame(DocumentNamingSource::Counterparty, $preference->description);
        $this->assertTrue($preference->showDescription);
    }

    public function test_le_os_padroes_da_empresa(): void
    {
        $company = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::System,
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company);

        $this->assertSame(DocumentNamingSource::System, $preference->name);
        $this->assertSame(DocumentNamingSource::Counterparty, $preference->code);
        $this->assertFalse($preference->showDescription);
    }

    public function test_empresa_nula_devolve_o_default(): void
    {
        $this->assertEquals(NamingPreference::default(), NamingPreference::fromCompany(null));
    }

    public function test_overrides_do_modal_vencem_a_empresa(): void
    {
        $company = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::System,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides([
            'naming_name_source' => 'counterparty',
            'show_description' => false,
        ]);

        $this->assertSame(DocumentNamingSource::Counterparty, $preference->name);
        $this->assertFalse($preference->showDescription);
    }

    public function test_override_ausente_nao_altera_o_valor(): void
    {
        $preference = NamingPreference::default()->withOverrides(['irrelevante' => true]);

        $this->assertEquals(NamingPreference::default(), $preference);
    }

    public function test_show_description_em_branco_nao_altera_o_valor_atual(): void
    {
        $preference = NamingPreference::default()->withOverrides(['show_description' => '']);

        $this->assertTrue($preference->showDescription);
    }

    public function test_show_description_nulo_nao_altera_o_valor_atual(): void
    {
        $preference = NamingPreference::default()->withOverrides(['show_description' => null]);

        $this->assertTrue($preference->showDescription);
    }

    public function test_show_description_false_desliga_a_descricao(): void
    {
        $preference = NamingPreference::default()->withOverrides(['show_description' => false]);

        $this->assertFalse($preference->showDescription);
    }

    public function test_show_description_em_branco_preserva_false_salvo_na_empresa(): void
    {
        $company = Company::factory()->create([
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides(['show_description' => '']);

        $this->assertFalse($preference->showDescription);
    }

    public function test_show_description_nulo_preserva_false_salvo_na_empresa(): void
    {
        $company = Company::factory()->create([
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides(['show_description' => null]);

        $this->assertFalse($preference->showDescription);
    }

    public function test_show_description_false_mantem_false_salvo_na_empresa(): void
    {
        $company = Company::factory()->create([
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides(['show_description' => false]);

        $this->assertFalse($preference->showDescription);
    }
}
