<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PackingListPdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\ProformaInvoicePdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PurchaseOrderPdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\ShipmentProformaInvoicePdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Carton;
use App\Domain\Logistics\Models\CartonContent;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O mesmo produto, num mesmo negócio, sai IDÊNTICO em todos os documentos do
 * cliente — e com a identidade do fornecedor nos documentos do fornecedor.
 *
 * Era exatamente isto que não acontecia antes: CI e Packing List usavam o
 * código/nome/descrição do cliente, a Proforma Invoice usava o nome interno, e
 * o Purchase Order o SKU interno.
 */
class ProductIdentityAcrossDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_CODE = 'CLI-778';

    private const CLIENT_NAME = 'Polia Tensora 120mm';

    private const CLIENT_DESCRIPTION = 'Polia tensora para colheitadeira';

    private const SUPPLIER_CODE = 'SUP-001';

    private const SUPPLIER_NAME = '张紧轮 120mm';

    private Company $client;

    private Company $supplier;

    private Product $product;

    private ProformaInvoice $pi;

    private Shipment $shipment;

    private ShipmentItem $shipmentItem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'Parity Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);
        $this->supplier = Company::create(['name' => 'Parity Supplier', 'status' => 'active']);
        $this->supplier->companyRoles()->create(['role' => 'supplier']);

        $this->product = Product::factory()->create([
            'name' => 'Internal Idler Pulley',
            'model_number' => 'MOD-INTERNAL',
            'sku' => 'SKU-INTERNAL',
        ]);

        $this->product->companies()->attach($this->client->id, [
            'role' => 'client',
            'external_code' => self::CLIENT_CODE,
            'external_name' => self::CLIENT_NAME,
            'external_description' => self::CLIENT_DESCRIPTION,
        ]);
        $this->product->companies()->attach($this->supplier->id, [
            'role' => 'supplier',
            'external_code' => self::SUPPLIER_CODE,
            'external_name' => self::SUPPLIER_NAME,
        ]);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-PAR-1',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $this->pi = ProformaInvoice::create([
            'reference' => 'PI-PAR-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        // Descrição auto-preenchida com o nome do produto, como faz a UI.
        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $this->pi->id,
            'product_id' => $this->product->id,
            'description' => 'Internal Idler Pulley',
            'quantity' => 10,
            'unit_price' => 100000,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-PAR-1',
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'issue_date' => '2026-08-01',
        ]);

        $this->shipmentItem = ShipmentItem::create([
            'shipment_id' => $this->shipment->id,
            'proforma_invoice_item_id' => $piItem->id,
            'quantity' => 10,
            'unit' => 'pcs',
            'sort_order' => 0,
        ]);

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
            'shipment_item_id' => $this->shipmentItem->id,
            'pieces' => 10,
            'sort_order' => 1,
        ]);
    }

    public function test_client_documents_all_show_the_same_client_code(): void
    {
        $pi = (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en'))->getData()['items'][0];
        $ps = (new PaymentStatementPdfTemplate($this->pi->fresh(), 'en'))->getData()['pi_items'][0];
        $ci = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'][0];
        $spi = (new ShipmentProformaInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'][0];
        $pl = (new PackingListPdfTemplate($this->shipment->fresh(), 'en'))
            ->getData()['container_groups'][0]['lines'][0];

        $this->assertSame(self::CLIENT_CODE, $pi['model_number'], 'Proforma Invoice');
        $this->assertSame(self::CLIENT_CODE, $ps['product_code'], 'Payment Statement');
        $this->assertSame(self::CLIENT_CODE, $ci['model_no'], 'Commercial Invoice');
        $this->assertSame(self::CLIENT_CODE, $spi['model_no'], 'Shipment Proforma Invoice');
        $this->assertSame(self::CLIENT_CODE, $pl['model_no'], 'Packing List');
    }

    public function test_client_documents_all_show_the_same_client_wording(): void
    {
        $pi = (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en'))->getData()['items'][0];
        $ps = (new PaymentStatementPdfTemplate($this->pi->fresh(), 'en'))->getData()['pi_items'][0];
        $ci = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'][0];
        $pl = (new PackingListPdfTemplate($this->shipment->fresh(), 'en'))
            ->getData()['container_groups'][0]['lines'][0];

        // A descrição da linha é auto-preenchida, então a descrição cadastrada
        // do cliente vence em todos os documentos.
        $this->assertSame(self::CLIENT_DESCRIPTION, $pi['description'], 'Proforma Invoice');
        $this->assertSame(self::CLIENT_DESCRIPTION, $ps['description'], 'Payment Statement');
        $this->assertSame(self::CLIENT_DESCRIPTION, $ci['description'], 'Commercial Invoice');

        // Onde há coluna de nome, é o nome do cliente.
        $this->assertSame(self::CLIENT_NAME, $ci['product_name'], 'Commercial Invoice');
        $this->assertSame(self::CLIENT_NAME, $pl['product_name'], 'Packing List');
    }

    public function test_client_documents_never_leak_internal_or_supplier_identity(): void
    {
        $payloads = [
            'Proforma Invoice' => (new ProformaInvoicePdfTemplate($this->pi->fresh(), 'en'))->getData()['items'],
            'Payment Statement' => (new PaymentStatementPdfTemplate($this->pi->fresh(), 'en'))->getData()['pi_items'],
            'Commercial Invoice' => (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData()['items'],
            'Packing List' => (new PackingListPdfTemplate($this->shipment->fresh(), 'en'))->getData()['container_groups'],
        ];

        foreach ($payloads as $document => $payload) {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

            $this->assertStringNotContainsString(self::SUPPLIER_CODE, $json, $document.' vazou o código do fornecedor');
            $this->assertStringNotContainsString(self::SUPPLIER_NAME, $json, $document.' vazou o nome do fornecedor');
            $this->assertStringNotContainsString('MOD-INTERNAL', $json, $document.' mostrou o model number interno');
        }
    }

    public function test_supplier_document_shows_the_supplier_identity_for_the_same_product(): void
    {
        $po = PurchaseOrder::factory()->create([
            'proforma_invoice_id' => $this->pi->id,
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'description' => 'Internal Idler Pulley',
            'quantity' => 10,
            'unit_cost' => 50000,
            'sort_order' => 1,
        ]);

        $item = (new PurchaseOrderPdfTemplate($po->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame(self::SUPPLIER_CODE, $item['product_code']);

        $json = json_encode($item, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString(self::CLIENT_CODE, $json, 'o PO vazou o código do cliente');
        $this->assertStringNotContainsString(self::CLIENT_DESCRIPTION, $json, 'o PO vazou a descrição do cliente');
    }
}
