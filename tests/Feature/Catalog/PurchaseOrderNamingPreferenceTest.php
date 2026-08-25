<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\PurchaseOrderPdfTemplate;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Purchase Order constrói o ProductIdentityResolver com
 * forSupplierCompany() — sem parent:, fornecedor não tem filial — então a
 * preferência de nomenclatura tem que ser honrada na linha impressa.
 *
 * Assim como a Proforma Invoice, o PO não tem coluna de "nome" dedicada — só
 * 'description', que cai para identity->name quando a descrição está vazia.
 * A linha, o pivot e o produto abaixo NUNCA carregam texto de descrição
 * nenhum, então resolveDescription() sempre devolve null e 'description'
 * reflete identity->name puro — sem isso, uma descrição de linha
 * auto-preenchida (igual ao nome do produto) faria os dois testes passarem
 * mesmo com a preferência de nome ignorada, só por coincidência de texto.
 */
class PurchaseOrderNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const SUPPLIER_NAME = 'Nome do Fornecedor Para o Produto';

    private function makeSupplierWithProduct(array $companyAttributes = []): array
    {
        $supplier = Company::create(array_merge([
            'name' => 'PO Naming Supplier '.uniqid(),
            'status' => 'active',
        ], $companyAttributes));
        $supplier->companyRoles()->create(['role' => 'supplier']);

        $product = Product::factory()->create([
            'name' => 'Internal Product Name',
            'description' => null,
        ]);
        $product->companies()->attach($supplier->id, [
            'role' => 'supplier',
            'external_name' => self::SUPPLIER_NAME,
        ]);
        $product->load('companies');

        return [$supplier, $product];
    }

    private function makePoWithItem(Company $supplier, Product $product): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_company_id' => $supplier->id,
            'currency_code' => 'USD',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'description' => null,
            'quantity' => 10,
            'unit_cost' => 1000,
            'sort_order' => 1,
        ]);

        return $po;
    }

    public function test_system_name_source_prints_the_products_own_name_not_the_supplier_alias(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $po = $this->makePoWithItem($supplier, $product);

        $item = (new PurchaseOrderPdfTemplate($po->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::SUPPLIER_NAME, $item['description']);
    }

    /**
     * Empresa nos padrões (COUNTERPARTY) — só o $options desta geração pede
     * SYSTEM. É este teste que a mutação "derrubar overrides:" precisa
     * quebrar.
     */
    public function test_modal_override_wins_over_the_companys_default_naming_preference(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct();

        $po = $this->makePoWithItem($supplier, $product);

        $item = (new PurchaseOrderPdfTemplate(
            $po->fresh(),
            'en',
            withImages: false,
            options: ['naming_name_source' => 'system']
        ))->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::SUPPLIER_NAME, $item['description']);
    }

    /**
     * Reprodução do defeito relatado na review final: sem o guard de
     * descriptionHidden, `$identity->description ?: $identity->name` cai
     * direto no nome do produto quando a descrição é escondida — a coluna
     * "Description" nunca fica vazia, só troca de fonte. 'Internal Product
     * Name' é o fallback não-vazio que expõe isso.
     */
    public function test_show_description_false_empties_the_description_instead_of_falling_back_to_the_name(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct([
            'document_show_description' => false,
        ]);

        $po = $this->makePoWithItem($supplier, $product);

        $item = (new PurchaseOrderPdfTemplate($po->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('', $item['description']);
    }
}
