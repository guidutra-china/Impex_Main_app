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
        //
        // specifications preenchida de propósito: é o fallback que a CI usa
        // quando identity->description vem vazio (`?: $piItem?->specifications`).
        // Sem um valor não-vazio aqui, um teste de "esconder descrição" passa
        // pela razão errada — o fallback já estava vazio por acidente, não
        // porque o guard de descriptionHidden funcionou.
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $this->product->id,
            'description' => 'Internal Product Name',
            'specifications' => 'Line specifications text',
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

    /**
     * O fixture (setUp) grava 'Line specifications text' em
     * $piItem->specifications de propósito: é o fallback que
     * buildInvoiceItems() usa quando identity->description vem vazio. Antes
     * do fix de descriptionHidden, esconder a descrição fazia
     * resolveDescription() devolver null e o `?:` cair direto nessa
     * specifications — a coluna "Description" imprimia texto interno da PI
     * em vez de ficar vazia. Este teste prova que não sobra NENHUM texto,
     * nem o da PI.
     */
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

    /**
     * Monta um shipment próprio, com PI/Inquiry pertencendo à MESMA empresa
     * que fatura — diferente do fixture de setUp(), que é sempre do
     * $this->client. Os testes de filial abaixo precisavam de uma empresa
     * faturadora diferente; reatribuir shipment->company_id no fixture de
     * setUp() sem refazer a cadeia PI/Inquiry descreveria um estado
     * impossível em produção (PI de uma empresa, shipment de outra) — foi
     * exatamente isso que o primeiro teste de filial fazia antes desta
     * extração.
     */
    private function shipmentBilledBy(Company $billingCompany, ?Company $branch = null): Shipment
    {
        $inquiry = Inquiry::create([
            'reference' => 'INQ-BR-'.$billingCompany->id,
            'company_id' => $billingCompany->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-BR-'.$billingCompany->id,
            'inquiry_id' => $inquiry->id,
            'company_id' => $billingCompany->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $shipment = Shipment::create([
            'reference' => 'SH-BR-'.$billingCompany->id,
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

        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        return $shipment;
    }

    /**
     * forClientCompany(?Company $company, ?Company $parent = null, ...) tem
     * dois argumentos do mesmo tipo — nada no PHP impede trocar a ordem, e
     * todo teste que usa um shipment sem company_branch_id não enxerga a
     * troca, porque getDocumentClient() e $shipment->company devolvem a
     * MESMA empresa nesse caso. Este teste exige um shipment endereçado a
     * uma filial com preferência PRÓPRIA — diferente da matriz — para que a
     * ordem realmente importe.
     */
    public function test_branch_naming_preference_wins_over_the_parent_companys(): void
    {
        $hq = Company::create([
            'name' => 'Naming HQ',
            'status' => 'active',
            // Se a ordem inverter (matriz entrando como "company" primário),
            // o nome cai para o cadastro interno do produto — bem diferente
            // do nome externo da filial abaixo.
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);
        $hq->companyRoles()->create(['role' => 'client']);

        $branch = Company::create([
            'name' => 'Naming Branch',
            'status' => 'active',
            'document_name_source' => DocumentNamingSource::COUNTERPARTY,
        ]);
        $branch->companyRoles()->create(['role' => 'client']);

        // Pivot só na filial — a mesma chamada que deriva a preferência
        // também deriva o pivot (filial > matriz), então um vínculo
        // exclusivo da filial só aparece na linha quando a ordem dos
        // argumentos está correta.
        $this->product->companies()->attach($branch->id, [
            'role' => 'client',
            'external_name' => 'Branch External Name',
        ]);
        $this->product->load('companies');

        $shipment = $this->shipmentBilledBy($hq, branch: $branch);

        $item = (new CommercialInvoicePdfTemplate($shipment->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('Branch External Name', $item['product_name']);
    }

    /**
     * O caso comum em produção não é a filial com vínculo próprio do teste
     * acima — é a filial que é só um endereço de cobrança, sem pivot e sem
     * nenhuma coluna document_* preenchida, herdando tudo da matriz. Esse
     * caminho só é exercitado quando parent: chega no resolver: derrubar o
     * argumento (em vez de trocar a ordem) não muda a preferência resultante
     * aqui — matriz e filial usam a mesma COUNTERPARTY por default — mas
     * quebra a busca do PIVOT, que também depende de parent como fallback.
     */
    public function test_branch_without_its_own_pivot_or_preference_falls_back_to_the_parent_company(): void
    {
        $hq = Company::create([
            'name' => 'Fallback HQ',
            'status' => 'active',
            'document_name_source' => DocumentNamingSource::COUNTERPARTY,
        ]);
        $hq->companyRoles()->create(['role' => 'client']);

        // Sem document_name_source próprio: exatamente o estado de uma
        // filial cadastrada só como endereço.
        $branch = Company::create(['name' => 'Fallback Branch', 'status' => 'active']);
        $branch->companyRoles()->create(['role' => 'client']);

        // Pivot só na matriz — a filial não tem vínculo próprio nenhum.
        $this->product->companies()->attach($hq->id, [
            'role' => 'client',
            'external_name' => 'HQ External Name',
        ]);
        $this->product->load('companies');

        $shipment = $this->shipmentBilledBy($hq, branch: $branch);

        $item = (new CommercialInvoicePdfTemplate($shipment->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('HQ External Name', $item['product_name']);
    }
}
