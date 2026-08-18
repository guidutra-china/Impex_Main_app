<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Precedência da descrição nos documentos:
 *
 *   descrição digitada de verdade > descrição da contraparte > descrição da linha
 *
 * "Digitada de verdade" = diferente do nome do produto. A descrição da linha é
 * auto-preenchida com o nome do produto por fillFromProduct(), então sem esse
 * teste a descrição cadastrada do cliente nunca apareceria.
 */
class ProductIdentityDescriptionTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::factory()->create();
    }

    private function productWithClientDescription(?string $externalDescription): Product
    {
        $product = Product::factory()->create([
            'name' => 'Idler Pulley 120mm',
            'sku' => 'SKU-'.uniqid(),
        ]);

        $product->companies()->attach($this->client->id, [
            'role' => 'client',
            'external_description' => $externalDescription,
        ]);

        return $product->load('companies');
    }

    private function resolve(Product $product, ?string $lineDescription): ?string
    {
        return ProductIdentityResolver::forClient($this->client->id)
            ->resolve($product, lineDescription: $lineDescription)
            ->description;
    }

    public function test_autofilled_line_yields_to_the_client_description(): void
    {
        $product = $this->productWithClientDescription('Polia tensora — uso agrícola');

        // fillFromProduct() copia product.name para a descrição da linha.
        $this->assertSame(
            'Polia tensora — uso agrícola',
            $this->resolve($product, 'Idler Pulley 120mm'),
        );
    }

    public function test_autofill_detection_ignores_punctuation_and_case(): void
    {
        $product = $this->productWithClientDescription('Descrição do cliente');

        $this->assertSame('Descrição do cliente', $this->resolve($product, 'IDLER PULLEY, 120MM'));
        $this->assertSame('Descrição do cliente', $this->resolve($product, 'idler-pulley 120 mm'));
    }

    public function test_deliberately_edited_line_wins_over_the_client_description(): void
    {
        $product = $this->productWithClientDescription('Descrição genérica do cliente');

        $this->assertSame(
            'Polia especial para este pedido',
            $this->resolve($product, 'Polia especial para este pedido'),
        );
    }

    public function test_blank_line_uses_the_client_description(): void
    {
        $product = $this->productWithClientDescription('Descrição do cliente');

        $this->assertSame('Descrição do cliente', $this->resolve($product, null));
        $this->assertSame('Descrição do cliente', $this->resolve($product, ''));
    }

    public function test_autofilled_line_is_still_printed_when_there_is_no_client_description(): void
    {
        $product = $this->productWithClientDescription(null);

        $this->assertSame('Idler Pulley 120mm', $this->resolve($product, 'Idler Pulley 120mm'));
    }

    public function test_nothing_to_show_returns_null(): void
    {
        $product = $this->productWithClientDescription(null);

        $this->assertNull($this->resolve($product, null));
    }

    /**
     * Limitação aceita e documentada: renomear o produto faz a descrição antiga
     * deixar de casar com o nome, então ela passa a contar como digitada. O
     * texto do snapshot é preservado — comportamento conservador correto.
     */
    public function test_renaming_the_product_makes_an_old_autofill_count_as_deliberate(): void
    {
        $product = $this->productWithClientDescription('Descrição do cliente');
        $product->update(['name' => 'Idler Pulley 120mm (v2)']);

        $this->assertSame('Idler Pulley 120mm', $this->resolve($product->fresh()->load('companies'), 'Idler Pulley 120mm'));
    }

    public function test_supplier_side_uses_the_supplier_description(): void
    {
        $supplier = Company::factory()->create();
        $product = Product::factory()->create(['name' => 'Idler Pulley 120mm']);
        $product->companies()->attach($supplier->id, [
            'role' => 'supplier',
            'external_description' => '张紧轮 120mm',
        ]);
        $product->load('companies');

        $description = ProductIdentityResolver::forSupplier($supplier->id)
            ->resolve($product, lineDescription: 'Idler Pulley 120mm')
            ->description;

        $this->assertSame('张紧轮 120mm', $description);
    }
}
