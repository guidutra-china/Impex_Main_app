<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Payment Statement constrói o ProductIdentityResolver com
 * forClientCompany() (sem parent: — PI é faturada direto à empresa, sem
 * conceito de filial), a mesma preferência que o PDF da própria PI honra.
 * A action que gera este documento (paymentStatementAction() em
 * PaymentScheduleRelationManager) não tem formulário — o $options do
 * template sempre chega vazio nesta geração, então só o cadastro da empresa
 * é exercitado aqui (não há override de modal para testar).
 *
 * Espelha ProformaInvoiceNamingPreferenceTest.php: o template não tem coluna
 * de "nome" dedicada — só 'description', que cai para identity->name quando
 * a descrição está vazia. A linha, o pivot e o produto abaixo NUNCA carregam
 * texto de descrição nenhum, para resolveDescription() sempre devolver null
 * e 'description' refletir identity->name puro — sem isso, uma descrição de
 * linha auto-preenchida (igual ao nome do produto) faria o teste passar
 * mesmo com a preferência de nome ignorada, só por coincidência de texto.
 */
class PaymentStatementNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_NAME = 'Nome do Cliente Para o Produto';

    private function makeClientWithProduct(array $companyAttributes = []): array
    {
        $client = Company::create(array_merge([
            'name' => 'Payment Statement Naming Client '.uniqid(),
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

    private function makePiWithItem(Company $client, Product $product): ProformaInvoice
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-PSN-'.$client->id,
            'company_id' => $client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PSN-'.$client->id,
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

    public function test_system_name_source_prints_the_products_own_name_not_the_client_alias(): void
    {
        [$client, $product] = $this->makeClientWithProduct([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $pi = $this->makePiWithItem($client, $product);

        $item = (new PaymentStatementPdfTemplate($pi->fresh()))->getData()['pi_items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::CLIENT_NAME, $item['description']);
    }

    /**
     * A action que gera este documento não tem formulário, mas o wiring em
     * si aceita overrides via $options do construtor — é este teste que a
     * mutação "derrubar overrides:" precisa quebrar.
     */
    public function test_options_override_wins_over_the_companys_default_naming_preference(): void
    {
        [$client, $product] = $this->makeClientWithProduct();

        $pi = $this->makePiWithItem($client, $product);

        $item = (new PaymentStatementPdfTemplate(
            $pi->fresh(),
            'en',
            ['naming_name_source' => 'system']
        ))->getData()['pi_items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::CLIENT_NAME, $item['description']);
    }

    /**
     * Sem o guard de descriptionHidden, `$identity->description ?: $identity->name`
     * cai direto no nome do produto quando a descrição é escondida — a
     * coluna nunca fica vazia, só troca de fonte. 'Internal Product Name' é
     * o fallback não-vazio que expõe isso.
     */
    public function test_show_description_false_empties_the_description_instead_of_falling_back_to_the_name(): void
    {
        [$client, $product] = $this->makeClientWithProduct([
            'document_show_description' => false,
        ]);

        $pi = $this->makePiWithItem($client, $product);

        $item = (new PaymentStatementPdfTemplate($pi->fresh()))->getData()['pi_items'][0];

        $this->assertSame('', $item['description']);
    }
}
