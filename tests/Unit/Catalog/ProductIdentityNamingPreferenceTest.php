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
        $preference = new NamingPreference(name: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('DPF-OBB', $identity->code);
        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_codigo_do_sistema_cai_para_model_number(): void
    {
        $preference = new NamingPreference(code: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('MOD-1', $identity->code);
        $this->assertFalse($identity->fromCounterparty);
    }

    public function test_codigo_do_sistema_sem_model_number_cai_para_sku(): void
    {
        $preference = new NamingPreference(code: DocumentNamingSource::SYSTEM);
        $product = $this->linkedProduct([], ['model_number' => null, 'sku' => 'SKU-XYZ']);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($product);

        $this->assertSame('SKU-XYZ', $identity->code);
    }

    public function test_nome_do_sistema_ignora_o_snapshot_da_linha(): void
    {
        $preference = new NamingPreference(name: DocumentNamingSource::SYSTEM);

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct(), lineName: 'Nome da linha');

        $this->assertSame('Olympic bearing bar', $identity->name);
    }

    public function test_ncm_nao_e_afetado_por_nenhum_toggle(): void
    {
        $preference = new NamingPreference(
            code: DocumentNamingSource::SYSTEM,
            name: DocumentNamingSource::SYSTEM,
            description: DocumentNamingSource::SYSTEM,
            showDescription: false,
        );

        $identity = ProductIdentityResolver::forClient($this->client->id, null, $preference)
            ->resolve($this->linkedProduct());

        $this->assertSame('9506.91.00', $identity->ncm);
    }
}
