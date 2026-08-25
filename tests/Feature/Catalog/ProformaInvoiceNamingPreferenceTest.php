<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Proforma Invoice constrói o ProductIdentityResolver com
 * forClientCompany() (sem parent: — PI é faturada direto à empresa, sem
 * conceito de filial), então a preferência cadastrada na empresa (ou
 * sobreposta via $options do construtor) tem que ser honrada na linha
 * impressa.
 *
 * O template não tem uma coluna de "nome" dedicada — só 'description', que
 * cai para identity->name quando a descrição está vazia. Por isso a linha,
 * o pivot e o produto abaixo NUNCA carregam texto de descrição nenhum
 * (nem auto-preenchido, nem do pivot, nem do cadastro): assim
 * resolveDescription() sempre devolve null, 'description' sempre reflete
 * identity->name puro, e o teste não pode "passar por coincidência" só
 * porque a descrição da linha calha de bater com o nome do produto.
 */
class ProformaInvoiceNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_NAME = 'Nome do Cliente Para o Produto';

    private function makePiWithItem(Company $client, Product $product): ProformaInvoice
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-PIN-'.$client->id,
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PIN-'.$client->id,
            'inquiry_id' => $inquiry->id,
            'company_id' => $client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $product->id,
            'description' => null,
            'quantity' => 10,
            'unit_price' => 100000,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        return $pi;
    }

    private function makeClientWithProduct(array $companyAttributes = []): array
    {
        $client = Company::create(array_merge([
            'name' => 'PI Naming Client '.uniqid(),
            'status' => 'active',
        ], $companyAttributes));
        $client->companyRoles()->create(['role' => 'client']);

        $product = Product::factory()->create([
            'name' => 'Internal Product Name',
            'description' => null,
        ]);
        $product->companies()->attach($client->id, [
            'role' => 'client',
            'external_name' => self::CLIENT_NAME,
        ]);
        $product->load('companies');

        return [$client, $product];
    }

    public function test_system_name_source_prints_the_products_own_name_not_the_client_alias(): void
    {
        [$client, $product] = $this->makeClientWithProduct([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $pi = $this->makePiWithItem($client, $product);

        $item = (new ProformaInvoicePdfTemplate($pi->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::CLIENT_NAME, $item['description']);
    }

    /**
     * Empresa nos padrões (COUNTERPARTY) — só o $options desta geração pede
     * SYSTEM. É este teste, e não o de cima (que só toca as colunas da
     * empresa), que a mutação "derrubar overrides:" precisa quebrar.
     */
    public function test_modal_override_wins_over_the_companys_default_naming_preference(): void
    {
        [$client, $product] = $this->makeClientWithProduct();

        $pi = $this->makePiWithItem($client, $product);

        $item = (new ProformaInvoicePdfTemplate(
            $pi->fresh(),
            'en',
            options: ['naming_name_source' => 'system']
        ))->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::CLIENT_NAME, $item['description']);
    }

    /**
     * O template usa `$identity->description ?: $identity->name` — sem o
     * guard de descriptionHidden, esconder a descrição faz resolveDescription()
     * devolver null e o `?:` cair direto no nome do produto (o fallback
     * histórico desta coluna). 'Internal Product Name' está preenchido de
     * propósito no produto: é exatamente esse fallback não-vazio que provava
     * o defeito (a coluna nunca ficava vazia, só mudava de fonte).
     */
    public function test_show_description_false_empties_the_description_instead_of_falling_back_to_the_name(): void
    {
        [$client, $product] = $this->makeClientWithProduct([
            'document_show_description' => false,
        ]);

        $pi = $this->makePiWithItem($client, $product);

        $item = (new ProformaInvoicePdfTemplate($pi->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('', $item['description']);
    }
}
