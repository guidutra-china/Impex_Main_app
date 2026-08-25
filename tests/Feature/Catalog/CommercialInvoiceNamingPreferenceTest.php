<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A Commercial Invoice constrói o ProductIdentityResolver com
 * forClientCompany(), então a preferência de nomenclatura cadastrada na
 * empresa (ou sobreposta pelo modal) tem que ser honrada na linha impressa —
 * não só no resolver isoladamente. Por isso os testes aqui passam sempre
 * por CommercialInvoicePdfTemplate::getData(), nunca chamando o resolver
 * direto: o ponto é provar a fiação, e um teste que pula o template passaria
 * mesmo se a fiação estivesse quebrada.
 */
class CommercialInvoiceNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_CODE = 'CLI-NAME-1';

    private const CLIENT_NAME = 'Nome do Cliente Para o Produto';

    private const CLIENT_DESCRIPTION = 'Descrição do cliente para o produto';

    private Company $client;

    private Product $product;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Naming Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->product = Product::factory()->create([
            'name' => 'Internal Product Name',
        ]);

        $this->product->companies()->attach($this->client->id, [
            'role' => 'client',
            'external_code' => self::CLIENT_CODE,
            'external_name' => self::CLIENT_NAME,
            'external_description' => self::CLIENT_DESCRIPTION,
            'external_ncm' => '9506.91.00',
        ]);
        $this->product->load('companies');

        $inquiry = Inquiry::create([
            'reference' => 'INQ-NAME-1',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-NAME-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-NAME-1',
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'issue_date' => '2026-08-01',
        ]);

        // Descrição auto-preenchida com o nome do produto, como faz a UI —
        // assim "isDeliberate" não trava a descrição na linha e o resolver
        // fica livre para escolher a fonte pela preferência.
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $this->product->id,
            'description' => 'Internal Product Name',
            'quantity' => 10,
            'unit_price' => 100000,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);
    }

    public function test_company_with_defaults_prints_exactly_the_historical_client_wording(): void
    {
        $item = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame(self::CLIENT_CODE, $item['model_no']);
        $this->assertSame(self::CLIENT_NAME, $item['product_name']);
        $this->assertSame(self::CLIENT_DESCRIPTION, $item['description']);
    }

    public function test_system_name_source_prints_the_products_own_name_not_the_client_alias(): void
    {
        $this->client->update(['document_name_source' => DocumentNamingSource::SYSTEM]);

        $item = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['product_name']);
        $this->assertNotSame(self::CLIENT_NAME, $item['product_name']);
    }

    public function test_show_description_false_empties_the_line_description(): void
    {
        $this->client->update(['document_show_description' => false]);

        $item = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('', $item['description']);
    }

    public function test_modal_override_wins_over_the_companys_default_naming_preference(): void
    {
        // Empresa nos padrões (COUNTERPARTY) — só o modal desta geração pede
        // SYSTEM. Prova que $this->options chega até o resolver, não só o
        // cadastro da empresa.
        $item = (new CommercialInvoicePdfTemplate(
            $this->shipment->fresh(),
            'en',
            ['naming_name_source' => 'system']
        ))->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['product_name']);
        $this->assertNotSame(self::CLIENT_NAME, $item['product_name']);
    }

    public function test_ncm_is_unaffected_by_naming_preference_and_still_prints_four_digits(): void
    {
        $this->client->update([
            'document_name_source' => DocumentNamingSource::SYSTEM,
            'document_show_description' => false,
        ]);

        $item = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('9506', $item['ncm']);
    }
}
