<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Packing List constrói o ProductIdentityResolver com forClientCompany(),
 * então a preferência de nomenclatura tem que chegar na linha impressa —
 * não só no resolver isoladamente. Espelha
 * CommercialInvoiceNamingPreferenceTest, incluindo o mesmo guard de filial:
 * o Packing List é o único outro documento do cliente com fallback de
 * filial (company: / parent:), então a mesma armadilha de troca de
 * argumentos se aplica aqui.
 */
class PackingListNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_NAME = 'Nome do Cliente Para o Produto';

    private Company $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'PL Naming Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->product = Product::factory()->create([
            'name' => 'Internal Product Name',
        ]);

        $this->product->companies()->attach($this->client->id, [
            'role' => 'client',
            'external_name' => self::CLIENT_NAME,
        ]);
        $this->product->load('companies');
    }

    private function shipmentBilledBy(Company $billingCompany, ?Company $branch = null): Shipment
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-PL-'.$billingCompany->id,
            'company_id' => $billingCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-PL-'.$billingCompany->id,
            'inquiry_id' => $inquiry->id,
            'company_id' => $billingCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SH-PL-'.$billingCompany->id,
            'company_id' => $billingCompany->id,
            'company_branch_id' => $branch?->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'issue_date' => '2026-08-01',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $this->product->id,
            'description' => 'Internal Product Name',
            'quantity' => 10,
            'unit_price' => 100000,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        $shipmentItem = ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        $carton = Carton::create([
            'shipment_id' => $shipment->id,
            'label' => 'BOX-1',
            'packaging_type' => 'CARTON',
            'gross_weight' => 5.0,
            'net_weight' => 4.5,
            'volume' => 0.02,
            'sort_order' => 1,
        ]);
        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $shipmentItem->id,
            'pieces' => 10,
            'sort_order' => 1,
        ]);

        return $shipment;
    }

    public function test_system_name_source_prints_the_products_own_name_not_the_client_alias(): void
    {
        $this->client->update(['document_name_source' => DocumentNamingSource::SYSTEM]);

        $shipment = $this->shipmentBilledBy($this->client);

        $line = (new PackingListPdfTemplate($shipment->fresh(), 'en'))
            ->getData()['container_groups'][0]['lines'][0];

        $this->assertSame('Internal Product Name', $line['product_name']);
        $this->assertNotSame(self::CLIENT_NAME, $line['product_name']);
    }

    /**
     * Empresa nos padrões (COUNTERPARTY) — só o $options desta geração pede
     * SYSTEM. É este teste, e não o de cima (que só toca as colunas da
     * empresa), que a mutação "derrubar overrides:" precisa quebrar.
     */
    public function test_modal_override_wins_over_the_companys_default_naming_preference(): void
    {
        $shipment = $this->shipmentBilledBy($this->client);

        $line = (new PackingListPdfTemplate(
            $shipment->fresh(),
            'en',
            ['naming_name_source' => 'system']
        ))->getData()['container_groups'][0]['lines'][0];

        $this->assertSame('Internal Product Name', $line['product_name']);
        $this->assertNotSame(self::CLIENT_NAME, $line['product_name']);
    }

    /**
     * Pin: PackingListPdfTemplate já era o único (com o RfqPdfTemplate) que
     * não caía num fallback quando a descrição é escondida — o site usa
     * `filled($identity->description) ? ... : null`, sem `?:` para outro
     * campo. O produto vem com descrição não-vazia (Product::factory()
     * preenche um parágrafo por padrão) só para provar que, mesmo havendo
     * texto disponível em algum lugar, a linha impressa fica vazia.
     */
    public function test_show_description_false_empties_the_line_description(): void
    {
        $this->client->update(['document_show_description' => false]);

        $shipment = $this->shipmentBilledBy($this->client);

        $line = (new PackingListPdfTemplate($shipment->fresh(), 'en'))
            ->getData()['container_groups'][0]['lines'][0];

        $this->assertNull($line['description']);
    }

    /**
     * Mesmo guard que o Commercial Invoice precisou: se company: e parent:
     * trocarem de lugar dentro de forClientCompany(), o pivot exclusivo da
     * filial deixa de ser encontrado (cai para o pivot inexistente da
     * matriz) e a linha volta a imprimir o nome interno do produto — só um
     * fixture com pivot e preferência PRÓPRIOS da filial (divergentes da
     * matriz) expõe essa troca.
     */
    public function test_branch_naming_preference_wins_over_the_parent_companys(): void
    {
        $hq = Company::create([
            'name' => 'PL Naming HQ',
            'status' => 'active',
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);
        $hq->companyRoles()->create(['role' => 'client']);

        $branch = Company::create([
            'name' => 'PL Naming Branch',
            'status' => 'active',
            'document_name_source' => DocumentNamingSource::COUNTERPARTY,
        ]);
        $branch->companyRoles()->create(['role' => 'client']);

        $this->product->companies()->attach($branch->id, [
            'role' => 'client',
            'external_name' => 'Branch External Name',
        ]);
        $this->product->load('companies');

        $shipment = $this->shipmentBilledBy($hq, branch: $branch);

        $line = (new PackingListPdfTemplate($shipment->fresh(), 'en'))
            ->getData()['container_groups'][0]['lines'][0];

        $this->assertSame('Branch External Name', $line['product_name']);
    }
}
