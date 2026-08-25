<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_preserva_o_comportamento_historico(): void
    {
        $preference = NamingPreference::default();

        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->code);
        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->name);
        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->description);
        $this->assertTrue($preference->showDescription);
    }

    public function test_le_os_padroes_da_empresa(): void
    {
        $company = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company);

        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->name);
        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->code);
        $this->assertFalse($preference->showDescription);
    }

    public function test_empresa_nula_devolve_o_default(): void
    {
        $this->assertEquals(NamingPreference::default(), NamingPreference::fromCompany(null));
    }

    public function test_overrides_do_modal_vencem_a_empresa(): void
    {
        $company = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides([
            NamingPreference::KEY_NAME => 'counterparty',
            NamingPreference::KEY_SHOW_DESCRIPTION => false,
        ]);

        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->name);
        $this->assertFalse($preference->showDescription);
    }

    public function test_override_ausente_nao_altera_o_valor(): void
    {
        $preference = NamingPreference::default()->withOverrides(['irrelevante' => true]);

        $this->assertEquals(NamingPreference::default(), $preference);
    }

    public function test_show_description_em_branco_nao_altera_o_valor_atual(): void
    {
        $preference = NamingPreference::default()->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => '']);

        $this->assertTrue($preference->showDescription);
    }

    public function test_show_description_nulo_nao_altera_o_valor_atual(): void
    {
        $preference = NamingPreference::default()->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => null]);

        $this->assertTrue($preference->showDescription);
    }

    public function test_show_description_false_desliga_a_descricao(): void
    {
        $preference = NamingPreference::default()->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => false]);

        $this->assertFalse($preference->showDescription);
    }

    public function test_show_description_em_branco_preserva_false_salvo_na_empresa(): void
    {
        $company = Company::factory()->create([
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => '']);

        $this->assertFalse($preference->showDescription);
    }

    public function test_show_description_nulo_preserva_false_salvo_na_empresa(): void
    {
        $company = Company::factory()->create([
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => null]);

        $this->assertFalse($preference->showDescription);
    }

    public function test_show_description_false_mantem_false_salvo_na_empresa(): void
    {
        $company = Company::factory()->create([
            'document_show_description' => false,
        ]);

        $preference = NamingPreference::fromCompany($company)->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => false]);

        $this->assertFalse($preference->showDescription);
    }

    #[DataProvider('provideFalseyStringsForShowDescription')]
    public function test_show_description_reconhece_strings_falsy_e_desliga(mixed $value): void
    {
        $preference = NamingPreference::default()->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => $value]);

        $this->assertFalse($preference->showDescription);
    }

    public static function provideFalseyStringsForShowDescription(): array
    {
        return [
            "'false'" => ['false'],
            "'off'" => ['off'],
            "'no'" => ['no'],
            "'0'" => ['0'],
            'inteiro 0' => [0],
        ];
    }

    #[DataProvider('provideTruthyStringsForShowDescription')]
    public function test_show_description_reconhece_strings_truthy_e_liga(mixed $value): void
    {
        $preference = NamingPreference::default()
            ->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => false])
            ->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => $value]);

        $this->assertTrue($preference->showDescription);
    }

    public static function provideTruthyStringsForShowDescription(): array
    {
        return [
            "'true'" => ['true'],
            "'1'" => ['1'],
            'inteiro 1' => [1],
            'booleano true' => [true],
        ];
    }

    public function test_show_description_string_nao_reconhecida_mantem_o_valor_atual(): void
    {
        $preference = NamingPreference::default()->withOverrides([NamingPreference::KEY_SHOW_DESCRIPTION => 'lixo']);

        $this->assertTrue($preference->showDescription);
    }

    public function test_as_tres_chaves_de_enum_sao_aplicadas_independentemente(): void
    {
        $preference = NamingPreference::default()->withOverrides([
            NamingPreference::KEY_CODE => 'system',
            NamingPreference::KEY_NAME => 'system',
            NamingPreference::KEY_DESCRIPTION => 'system',
        ]);

        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->code);
        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->name);
        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->description);
    }

    public function test_string_invalida_degrada_para_o_valor_atual_em_vez_de_estourar(): void
    {
        $preference = NamingPreference::default()->withOverrides([
            NamingPreference::KEY_CODE => 'lixo-invalido',
        ]);

        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->code);
    }

    public function test_filial_com_tudo_nulo_herda_todos_os_campos_da_matriz(): void
    {
        $matriz = Company::factory()->create([
            'document_code_source' => DocumentNamingSource::SYSTEM,
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_description_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);
        $filial = Company::factory()->create(['parent_company_id' => $matriz->id]);

        $preference = NamingPreference::fromCompany($filial, $matriz);

        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->code);
        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->name);
        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->description);
        $this->assertFalse($preference->showDescription);
    }

    public function test_filial_com_campo_proprio_vence_a_matriz(): void
    {
        $matriz = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);
        $filial = Company::factory()->create([
            'parent_company_id' => $matriz->id,
            'document_name_source' => DocumentNamingSource::COUNTERPARTY,
        ]);

        $preference = NamingPreference::fromCompany($filial, $matriz);

        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->name);
    }

    public function test_filial_herda_por_campo_nao_tudo_ou_nada(): void
    {
        $matriz = Company::factory()->create([
            'document_code_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);
        $filial = Company::factory()->create([
            'parent_company_id' => $matriz->id,
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $preference = NamingPreference::fromCompany($filial, $matriz);

        // Herdado da matriz, porque a filial não configurou.
        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->code);
        $this->assertFalse($preference->showDescription);
        // Próprio da filial, não o default da matriz (que está em branco também).
        $this->assertSame(DocumentNamingSource::SYSTEM, $preference->name);
        // Nem filial nem matriz configuraram: cai no histórico.
        $this->assertSame(DocumentNamingSource::COUNTERPARTY, $preference->description);
    }

    public function test_filial_e_matriz_nulas_devolvem_o_default(): void
    {
        $matriz = Company::factory()->create();
        $filial = Company::factory()->create(['parent_company_id' => $matriz->id]);

        $preference = NamingPreference::fromCompany($filial, $matriz);

        $this->assertEquals(NamingPreference::default(), $preference);
    }

    public function test_filial_explicita_vence_matriz_explicita_nas_duas_direcoes(): void
    {
        $matrizMostrando = Company::factory()->create(['document_show_description' => true]);
        $filialEscondendo = Company::factory()->create([
            'parent_company_id' => $matrizMostrando->id,
            'document_show_description' => false,
        ]);

        $this->assertFalse(NamingPreference::fromCompany($filialEscondendo, $matrizMostrando)->showDescription);

        $matrizEscondendo = Company::factory()->create(['document_show_description' => false]);
        $filialMostrando = Company::factory()->create([
            'parent_company_id' => $matrizEscondendo->id,
            'document_show_description' => true,
        ]);

        $this->assertTrue(NamingPreference::fromCompany($filialMostrando, $matrizEscondendo)->showDescription);
    }
}
