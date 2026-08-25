<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A preferência escolhe, campo a campo, se o pivot da contraparte é consultado.
 * O default continua sendo "o pivot vence", que é o comportamento histórico.
 *
 * forClient()/forSupplier() (ids) nunca aceitam preferência — servem quem
 * nunca precisou dela. forClientCompany()/forSupplierCompany() (empresas)
 * são o caminho real de produção: derivam pivot e preferência de uma vez,
 * então os testes daqui exercitam essas duas fábricas, não o construtor.
 */
class ProductIdentityNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = Company::factory()->create();

        // O contrato do resolver é nunca consultar o banco. As duas fontes
        // novas (SYSTEM em nome/descrição) só tocam atributos já carregados
        // hoje, mas nada as trava contra uma edição futura que alcance uma
        // relação — preventLazyLoading vira qualquer lazy load em exceção.
        Model::preventLazyLoading();
    }

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);
        parent::tearDown();
    }

    private function linkedProduct(array $pivot = [], array $attributes = [], ?Company $company = null): Product
    {
        $company ??= $this->client;

        $product = Product::factory()->create(array_merge([
            'name' => 'Olympic bearing bar',
            'description' => 'Barra em aço para treinos livres',
            'model_number' => 'MOD-1',
            'sku' => 'SKU-'.uniqid(),
        ], $attributes));

        $product->companies()->attach($company->id, array_merge([
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
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_NAME => DocumentNamingSource::SYSTEM,
        ]);

        $identity = $resolver->resolve($this->linkedProduct());

        $this->assertSame('DPF-OBB', $identity->code);
        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_codigo_do_sistema_cai_para_model_number(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_CODE => DocumentNamingSource::SYSTEM,
        ]);

        $identity = $resolver->resolve($this->linkedProduct());

        $this->assertSame('MOD-1', $identity->code);
        $this->assertFalse($identity->fromCounterparty);
    }

    public function test_codigo_do_sistema_sem_model_number_cai_para_sku(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_CODE => DocumentNamingSource::SYSTEM,
        ]);
        $product = $this->linkedProduct([], ['model_number' => null, 'sku' => 'SKU-XYZ']);

        $identity = $resolver->resolve($product);

        $this->assertSame('SKU-XYZ', $identity->code);
    }

    public function test_nome_do_sistema_ignora_o_snapshot_da_linha(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_NAME => DocumentNamingSource::SYSTEM,
        ]);

        $identity = $resolver->resolve($this->linkedProduct(), lineName: 'Nome da linha');

        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_ncm_nao_e_afetado_por_nenhum_toggle(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_CODE => DocumentNamingSource::SYSTEM,
            NamingPreference::KEY_NAME => DocumentNamingSource::SYSTEM,
            NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
            NamingPreference::KEY_SHOW_DESCRIPTION => false,
        ]);

        $identity = $resolver->resolve($this->linkedProduct());

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
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
        ]);

        $identity = $resolver->resolve($this->linkedProduct());

        $this->assertSame('Barra em aço para treinos livres', $identity->description);
    }

    public function test_texto_digitado_na_linha_vence_as_duas_fontes(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
        ]);

        $identity = $resolver->resolve($this->linkedProduct(), lineDescription: 'Special packing, 2 pcs/ctn');

        $this->assertSame('Special packing, 2 pcs/ctn', $identity->description);
    }

    public function test_ocultar_descricao_zera_inclusive_o_texto_digitado(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_SHOW_DESCRIPTION => false,
        ]);

        $identity = $resolver->resolve($this->linkedProduct(), lineDescription: 'Special packing, 2 pcs/ctn');

        $this->assertNull($identity->description);
    }

    public function test_descricao_do_sistema_vazia_nao_cai_para_o_cliente(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
        ]);
        $product = $this->linkedProduct([], ['description' => null]);

        $identity = $resolver->resolve($product);

        $this->assertNull($identity->description);
    }

    /**
     * A assimetria é deliberada (ver docblock de resolveDescription()): sob
     * SYSTEM, texto auto-preenchido na linha (== nome do produto, é a
     * assinatura de fillFromProduct()) NÃO socorre uma descrição de cadastro
     * vazia. Um mutante que adicionasse esse fallback faria a suíte inteira
     * passar sem isso — este teste existe só para pegar esse mutante.
     */
    public function test_descricao_do_sistema_ignora_texto_auto_preenchido_da_linha(): void
    {
        $resolver = ProductIdentityResolver::forClientCompany($this->client, overrides: [
            NamingPreference::KEY_DESCRIPTION => DocumentNamingSource::SYSTEM,
        ]);
        $product = $this->linkedProduct([], ['description' => null]);

        // Auto-preenchida = igual ao nome do produto após normalização.
        $identity = $resolver->resolve($product, lineDescription: $product->name);

        $this->assertNull($identity->description);
    }

    /**
     * O modo de drift que forClientCompany() existe para prevenir: filial com
     * as quatro colunas de preferência NULL herda da matriz campo a campo,
     * exatamente como já acontece para o pivot (filial > matriz). Antes desta
     * fábrica, quem esquecesse de repassar a matriz para uma das duas
     * derivações (pivot ou preferência) tinha esse caso quebrando em silêncio.
     */
    public function test_forclientcompany_filial_sem_preferencia_propria_herda_da_matriz(): void
    {
        $hq = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);
        $branch = Company::factory()->create();

        // O produto só tem pivot com a MATRIZ — a filial precisa do fallback
        // de pivot (branch > hq) para achar o código, e do fallback de
        // preferência (branch > hq) para achar o nome do sistema.
        $product = $this->linkedProduct(company: $hq);

        $resolver = ProductIdentityResolver::forClientCompany($branch, $hq);

        $identity = $resolver->resolve($product);

        $this->assertSame('DPF-OBB', $identity->code, 'pivot deveria cair para a matriz');
        $this->assertSame('Olympic bearing bar', $identity->name, 'nome deveria herdar SYSTEM da matriz');
    }

    /**
     * forSupplierCompany() segue o mesmo caminho de forClientCompany() do
     * lado fornecedor: deriva a preferência da própria empresa, sem fallback
     * de matriz (fornecedor não tem esse conceito hoje).
     */
    public function test_forsuppliercompany_aplica_preferencia_da_propria_empresa(): void
    {
        $supplier = Company::factory()->create([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $product = Product::factory()->create(['name' => 'Steel barbell']);
        $product->companies()->attach($supplier->id, [
            'role' => 'supplier',
            'external_code' => 'SUP-CODE',
            'external_name' => 'Barra de aço do fornecedor',
        ]);
        $product->load('companies');

        $identity = ProductIdentityResolver::forSupplierCompany($supplier)->resolve($product);

        $this->assertSame('SUP-CODE', $identity->code);
        $this->assertSame('Steel barbell', $identity->name);
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
