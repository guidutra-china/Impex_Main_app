<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A preferência escolhe, campo a campo, se o pivot da contraparte é consultado.
 * O default continua sendo "o pivot vence", que é o comportamento histórico.
 */
class ProductIdentityNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Company::factory()->create();
    }

    private function linkedProduct(array $pivot = [], array $attributes = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'name' => 'Olympic bearing bar',
            'description' => 'Barra em aço para treinos livres',
            'model_number' => 'MOD-1',
            'sku' => 'SKU-'.uniqid(),
        ], $attributes));

        $product->companies()->attach($this->client->id, array_merge([
            'role' => 'client',
            'external_code' => 'DPF-OBB',
            'external_name' => 'Barra Olímpica com Rolamentos',
            'external_description' => 'Olympic bearing bar, 1.5 m',
            'external_ncm' => '9506.91.00',
        ], $pivot));
        $product->load('companies');

        return $product;
    }

    public function test_sem_preferencia_o_pivot_vence_como_sempre(): void
    {
        $identity = ProductIdentityResolver::forClient($this->client->id)
            ->resolve($this->linkedProduct());

        $this->assertSame('DPF-OBB', $identity->code);
        $this->assertSame('Barra Olímpica com Rolamentos', $identity->name);
    }

    public function test_nome_do_sistema_mantendo_o_codigo_do_cliente(): void
    {
        $preference = NamingPreference::default()->with(name: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('DPF-OBB', $identity->code);
        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_codigo_do_sistema_cai_para_model_number(): void
    {
        $preference = NamingPreference::default()->with(code: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('MOD-1', $identity->code);
        $this->assertFalse($identity->fromCounterparty);
    }

    public function test_codigo_do_sistema_sem_model_number_cai_para_sku(): void
    {
        $preference = NamingPreference::default()->with(code: DocumentNamingSource::SYSTEM);
        $product = $this->linkedProduct([], ['model_number' => null, 'sku' => 'SKU-XYZ']);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($product);

        $this->assertSame('SKU-XYZ', $identity->code);
    }

    public function test_nome_do_sistema_ignora_o_snapshot_da_linha(): void
    {
        $preference = NamingPreference::default()->with(name: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct(), lineName: 'Nome da linha');

        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_ncm_nao_e_afetado_por_nenhum_toggle(): void
    {
        $preference = NamingPreference::default()->with(
            code: DocumentNamingSource::SYSTEM,
            name: DocumentNamingSource::SYSTEM,
            description: DocumentNamingSource::SYSTEM,
            showDescription: false,
        );

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('9506.91.00', $identity->ncm);
    }

    public function test_descricao_do_cliente_e_o_padrao(): void
    {
        $identity = ProductIdentityResolver::forClient($this->client->id)
            ->resolve($this->linkedProduct());

        $this->assertSame('Olympic bearing bar, 1.5 m', $identity->description);
    }

    public function test_descricao_do_sistema_usa_o_cadastro_do_produto(): void
    {
        $preference = NamingPreference::default()->with(description: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('Barra em aço para treinos livres', $identity->description);
    }

    public function test_texto_digitado_na_linha_vence_as_duas_fontes(): void
    {
        $preference = NamingPreference::default()->with(description: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct(), lineDescription: 'Special packing, 2 pcs/ctn');

        $this->assertSame('Special packing, 2 pcs/ctn', $identity->description);
    }

    public function test_ocultar_descricao_zera_inclusive_o_texto_digitado(): void
    {
        $preference = NamingPreference::default()->with(showDescription: false);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct(), lineDescription: 'Special packing, 2 pcs/ctn');

        $this->assertNull($identity->description);
    }

    public function test_descricao_do_sistema_vazia_nao_cai_para_o_cliente(): void
    {
        $preference = NamingPreference::default()->with(description: DocumentNamingSource::SYSTEM);
        $product = $this->linkedProduct([], ['description' => null]);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($product);

        $this->assertNull($identity->description);
    }

    /**
     * O histórico mora só em default(). with() nunca deve reintroduzir uma
     * segunda cópia dos valores default por trás de parâmetros opcionais do
     * construtor — trocar um campo via with() precisa deixar os outros três
     * exatamente iguais aos de default(), nunca a um default paralelo.
     */
    public function test_default_e_a_unica_fonte_do_historico(): void
    {
        $default = NamingPreference::default();
        $changed = $default->with(name: DocumentNamingSource::SYSTEM);

        $this->assertSame($default->code, $changed->code);
        $this->assertSame($default->description, $changed->description);
        $this->assertSame($default->showDescription, $changed->showDescription);
        $this->assertNotSame($default->name, $changed->name);
    }

    /**
     * Trava estrutural: o construtor não pode ter valores default nos quatro
     * campos promovidos. Se alguém reintroduzir defaults ali (mesmo batendo
     * com default() hoje), essa cópia paralela pode divergir de default() no
     * futuro sem que nenhum outro teste perceba, porque nada no código de
     * produção constrói a classe com argumentos parciais. Este teste é o que
     * de fato pega essa reintrodução.
     */
    public function test_construtor_nao_tem_defaults_paralelos_a_default(): void
    {
        $constructor = new \ReflectionMethod(NamingPreference::class, '__construct');

        foreach ($constructor->getParameters() as $parameter) {
            $this->assertFalse(
                $parameter->isDefaultValueAvailable(),
                "Parâmetro \${$parameter->getName()} do construtor não deveria ter default — "
                .'default() é a única fonte do histórico.'
            );
        }
    }
}
