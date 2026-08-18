<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
use App\Domain\Catalog\Reports\ClientProductsReportImporter;
use App\Domain\Catalog\Services\ProductIdentityResolver;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Pdf\Templates\CommercialInvoicePdfTemplate;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\Logistics\Models\ShipmentItem;
use App\Domain\Logistics\Reports\CommercialInvoiceExcelExporter;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * NCM é classificação fiscal do IMPORTADOR: vive no vínculo do cliente
 * (company_product.external_ncm), aparece na Commercial Invoice e nunca cai
 * para products.hs_code — que guarda o HS de 6 dígitos da origem.
 */
class ClientNcmTest extends TestCase
{
    use RefreshDatabase;

    private Company $client;

    private Product $product;

    private Shipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Company::create(['name' => 'NCM Client', 'status' => 'active']);
        $this->client->companyRoles()->create(['role' => 'client']);

        $this->product = Product::factory()->create([
            'name' => 'Idler Pulley',
            'model_number' => 'MOD-NCM',
            // HS chinês de 6 dígitos — NÃO é NCM e não pode virar um.
            'hs_code' => '843149',
        ]);

        $inquiry = Inquiry::create([
            'reference' => 'INQ-NCM-1',
            'company_id' => $this->client->id,
            'status' => 'received',
            'source' => 'email',
            'currency_code' => 'USD',
        ]);

        $pi = ProformaInvoice::create([
            'reference' => 'PI-NCM-1',
            'inquiry_id' => $inquiry->id,
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'issue_date' => '2026-08-01',
            'status' => 'confirmed',
        ]);

        $this->shipment = Shipment::create([
            'reference' => 'SH-NCM-1',
            'company_id' => $this->client->id,
            'currency_code' => 'USD',
            'status' => 'draft',
            'transport_mode' => 'sea',
            'issue_date' => '2026-08-01',
        ]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $this->product->id,
            'description' => 'Idler Pulley',
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

    private function linkClient(?string $ncm): void
    {
        $this->product->companies()->attach($this->client->id, [
            'role' => 'client',
            'external_code' => 'CLIENT-NCM',
            'external_ncm' => $ncm,
        ]);
        $this->product->load('companies');
    }

    public function test_resolver_reads_the_client_ncm_and_never_falls_back_to_hs_code(): void
    {
        $this->linkClient('84314900');

        $withNcm = ProductIdentityResolver::forClient($this->client->id)->resolve($this->product);
        $this->assertSame('84314900', $withNcm->ncm);

        // Outro produto do mesmo cliente, sem NCM cadastrado: fica vazio mesmo
        // com hs_code preenchido no produto.
        $other = Product::factory()->create(['hs_code' => '843149']);
        $other->companies()->attach($this->client->id, ['role' => 'client']);
        $other->load('companies');

        $withoutNcm = ProductIdentityResolver::forClient($this->client->id)->resolve($other);
        $this->assertNull($withoutNcm->ncm);
    }

    public function test_supplier_side_never_carries_an_ncm(): void
    {
        $supplier = Company::factory()->create();
        $this->product->companies()->attach($supplier->id, [
            'role' => 'supplier',
            'external_ncm' => '99999999',
        ]);
        $this->product->load('companies');

        $identity = ProductIdentityResolver::forSupplier($supplier->id)->resolve($this->product);

        $this->assertNull($identity->ncm);
    }

    public function test_commercial_invoice_shows_the_ncm_column_only_when_there_is_an_ncm(): void
    {
        $withoutNcm = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData();
        $this->assertFalse($withoutNcm['show_ncm']);
        $this->assertNull($withoutNcm['items'][0]['ncm']);

        $this->linkClient('84314900');

        $withNcm = (new CommercialInvoicePdfTemplate($this->shipment->fresh(), 'en'))->getData();
        $this->assertTrue($withNcm['show_ncm']);
        $this->assertSame('84314900', $withNcm['items'][0]['ncm']);
    }

    public function test_commercial_invoice_excel_adds_the_ncm_column_without_shifting_the_totals(): void
    {
        $this->linkClient('84314900');

        $path = (new CommercialInvoiceExcelExporter)->export($this->shipment->fresh());
        $rows = IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        @unlink($path);

        $header = collect($rows)->first(fn ($row) => in_array('MODEL NO.', array_map(fn ($c) => trim((string) $c), $row), true));
        $this->assertNotNull($header);
        $this->assertContains('NCM', array_map(fn ($c) => trim((string) $c), $header));

        $text = implode("\n", array_map(fn ($row) => implode('|', array_map(fn ($c) => (string) $c, $row)), $rows));
        $this->assertStringContainsString('84314900', $text);

        // Total continua correto com a coluna extra (10 × 10.00 = 100.00).
        $grandTotal = collect($rows)->first(fn ($row) => in_array('GRAND TOTAL', array_map(fn ($c) => trim((string) $c), $row), true));
        $this->assertNotNull($grandTotal);
        $this->assertContains(100.0, array_map(fn ($c) => is_numeric($c) ? (float) $c : $c, $grandTotal));
    }

    public function test_client_products_spreadsheet_round_trips_the_ncm_into_the_pivot(): void
    {
        $this->linkClient(null);

        $path = (new ClientProductsExcelExporter)->export($this->client);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Coluna M é a do NCM; preenche como o usuário faria.
        $dataRow = 5;
        $this->assertSame($this->product->sku, trim((string) $sheet->getCell('B'.$dataRow)->getValue()));
        $sheet->setCellValue('M'.$dataRow, '84314900');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        (new ClientProductsReportImporter)->import($this->client, $path);
        @unlink($path);

        $pivot = CompanyProduct::where('company_id', $this->client->id)
            ->where('product_id', $this->product->id)
            ->where('role', 'client')
            ->first();

        $this->assertSame('84314900', $pivot->external_ncm);
        // E o HS Code global do produto continua intocado.
        $this->assertSame('843149', $this->product->fresh()->hs_code);
    }
}
