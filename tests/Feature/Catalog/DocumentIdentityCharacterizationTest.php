<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
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
 * Rede de segurança da migração para o ProductIdentityResolver: fixa o que
 * Commercial Invoice e Packing List imprimem hoje para código, nome e
 * descrição — inclusive as convenções que NÃO devem mudar (CI usa "—" quando
 * não há código, PL deixa a célula vazia).
 */
class DocumentIdentityCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Shipment $shipment;

    private ProformaInvoice $pi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Characterization Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-CHAR-1',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-CHAR-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-CHAR-1',
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'origin_port' => 'Shenzhen',
            'destination_port' => 'Santos',
            'issue_date' => '2026-08-01',
        ]);
    }

    /**
     * @param  array<string, mixed>  $pivot  dados do pivot do cliente (null = sem pivot)
     */
    private function addItem(
        string $productName,
        ?array $pivot,
        ?string $lineDescription,
        ?string $modelNumber = 'MOD-1',
        int $sortOrder = 0,
    ): ShipmentItem {
        $product = Product::factory()->create([
            'name' => $productName,
            'model_number' => $modelNumber,
            'sku' => 'SKU-'.uniqid(),
        ]);

        if ($pivot !== null) {
            $product->companies()->attach($this->client->id, array_merge(['role' => 'client'], $pivot));
        }

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'product_id' => $product->id,
            'description' => $lineDescription,
            'quantity' => 10,
            'unit_price' => 100000,
            'unit' => 'pcs',
            'sort_order' => $sortOrder,
        ]);

        return ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'unit' => 'pcs',
            'sort_order' => $sortOrder,
        ]);
    }

    private function commercialInvoiceItems(): array
    {
        return (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'];
    }

    public function test_ci_code_follows_client_code_then_model_then_sku(): void
    {
        $this->addItem('Product A', ['external_code' => 'CLIENT-A'], null, sortOrder: 0);
        $this->addItem('Product B', ['external_code' => null], null, 'MOD-B', sortOrder: 1);
        $noCodes = $this->addItem('Product C', null, null, null, sortOrder: 2);

        $items = $this->commercialInvoiceItems();

        $this->assertSame('CLIENT-A', $items[0]['model_no']);
        $this->assertSame('MOD-B', $items[1]['model_no']);
        $this->assertSame(
            $noCodes->proformaInvoiceItem->product->sku,
            $items[2]['model_no'],
        );
    }

    public function test_ci_name_prefers_the_client_name(): void
    {
        $this->addItem('Internal Name', ['external_name' => 'Client Facing Name'], null, sortOrder: 0);
        $this->addItem('Only Internal', ['external_code' => 'X'], null, sortOrder: 1);

        $items = $this->commercialInvoiceItems();

        $this->assertSame('Client Facing Name', $items[0]['product_name']);
        $this->assertSame('Only Internal', $items[1]['product_name']);
    }

    public function test_ci_description_uses_the_client_description_over_an_autofilled_line(): void
    {
        // Linha auto-preenchida (igual ao nome do produto) + descrição do cliente.
        $this->addItem('Idler Pulley', ['external_description' => 'Polia tensora'], 'Idler Pulley', sortOrder: 0);
        // Sem descrição do cliente: cai para a descrição da linha.
        $this->addItem('Sieve', null, 'Peneira traseira', sortOrder: 1);

        $items = $this->commercialInvoiceItems();

        $this->assertSame('Polia tensora', $items[0]['description']);
        $this->assertSame('Peneira traseira', $items[1]['description']);
    }

    /**
     * Comportamento real (não intuitivo, por isso fixado aqui): o "—" da CI só
     * aparece quando a LINHA NÃO TEM PRODUTO. Com produto sem identificadores,
     * a CI também imprime célula vazia — igual ao Packing List.
     */
    public function test_ci_renders_em_dash_only_when_the_line_has_no_product(): void
    {
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'product_id' => null,
            'description' => 'Manual line',
            'quantity' => 1,
            'unit_price' => 1000,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);
        ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 1,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        $this->assertSame('—', $this->commercialInvoiceItems()[0]['model_no']);
    }

    public function test_ci_renders_empty_when_the_product_exists_without_identifiers(): void
    {
        $item = $this->addItem('No Identifiers', null, null, null, sortOrder: 0);
        $item->proformaInvoiceItem->product->forceFill(['sku' => ''])->saveQuietly();

        $this->assertSame('', $this->commercialInvoiceItems()[0]['model_no']);
    }

    public function test_packing_list_leaves_the_code_cell_empty_instead_of_em_dash(): void
    {
        $item = $this->addItem('No Identifiers', null, null, null, sortOrder: 0);
        $item->proformaInvoiceItem->product->forceFill(['sku' => ''])->saveQuietly();

        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-1',
            'packaging_type' => 'CARTON',
            'gross_weight' => 5.0,
            'net_weight' => 4.5,
            'volume' => 0.02,
            'sort_order' => 1,
        ]);
        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $item->id,
            'pieces' => 10,
            'sort_order' => 1,
        ]);

        $data = (new PackingListPdfTemplate($this->shipment->fresh(), 'en'))->getData();
        $line = $data['container_groups'][0]['lines'][0];

        $this->assertSame('', $line['model_no'], 'O Packing List mantém a célula vazia, não "—".');
    }

    public function test_packing_list_uses_the_client_code_and_name(): void
    {
        $item = $this->addItem('Internal Name', [
            'external_code' => 'CLIENT-PL',
            'external_name' => 'Client PL Name',
        ], null, sortOrder: 0);

        $carton = Carton::create([
            'shipment_id' => $this->shipment->id,
            'label' => 'BOX-1',
            'packaging_type' => 'CARTON',
            'gross_weight' => 5.0,
            'net_weight' => 4.5,
            'volume' => 0.02,
            'sort_order' => 1,
        ]);
        CartonContent::create([
            'carton_id' => $carton->id,
            'shipment_item_id' => $item->id,
            'pieces' => 10,
            'sort_order' => 1,
        ]);

        $line = (new PackingListPdfTemplate($this->shipment->fresh(), 'en'))
            ->getData()['container_groups'][0]['lines'][0];

        $this->assertSame('CLIENT-PL', $line['model_no']);
        $this->assertSame('Client PL Name', $line['product_name']);
    }

    public function test_ci_does_not_leak_the_supplier_code_when_the_company_is_both(): void
    {
        $product = Product::factory()->create(['name' => 'Dual Role', 'model_number' => 'MOD-DUAL']);
        $product->companies()->attach($this->client->id, ['role' => 'supplier', 'external_code' => 'SUPPLIER-CODE']);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1000,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);
        ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 1,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        $this->assertSame('MOD-DUAL', $this->commercialInvoiceItems()[0]['model_no']);
    }
}
