<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Excel\Templates\RfqExcelTemplate;
use App\Domain\Infrastructure\Pdf\Templates\PurchaseOrderPdfTemplate;
use App\Domain\Infrastructure\Pdf\Templates\RfqPdfTemplate;
use App\Domain\Planning\Actions\GenerateProductionScheduleTemplate;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\PurchaseOrders\Models\PurchaseOrderItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Documentos enviados ao fornecedor identificam o produto como ELE o conhece
 * (pivot role=supplier) — antes todos imprimiam o SKU e o nome internos, e os
 * campos do fornecedor não eram lidos por documento nenhum.
 */
class SupplierFacingDocumentIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Company $supplier;

    private Company $client;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Company::factory()->create(['name' => 'Shenzhen Supplier']);
        $this->client = Company::factory()->create(['name' => 'Brazilian Client']);

        $this->product = Product::factory()->create([
            'name' => 'Internal Product Name',
            'model_number' => 'MOD-INT',
            'sku' => 'SKU-INT',
        ]);

        // O mesmo produto tem identidade diferente para cada lado.
        $this->product->companies()->attach($this->supplier->id, [
            'role' => 'supplier',
            'external_code' => 'SUP-CODE-9',
            'external_name' => '张紧轮 120mm',
            'external_description' => 'Descrição do fornecedor',
        ]);
        $this->product->companies()->attach($this->client->id, [
            'role' => 'client',
            'external_code' => 'CLIENT-CODE-1',
            'external_name' => 'Client Product Name',
            'external_description' => 'Descrição do cliente',
        ]);
    }

    public function test_purchase_order_uses_the_supplier_identity(): void
    {
        $po = PurchaseOrder::factory()->create([
            'supplier_company_id' => $this->supplier->id,
            'currency_code' => 'USD',
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            // Descrição auto-preenchida com o nome do produto.
            'description' => 'Internal Product Name',
            'quantity' => 10,
            'unit_cost' => 1000,
            'sort_order' => 1,
        ]);

        $item = (new PurchaseOrderPdfTemplate($po->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('SUP-CODE-9', $item['product_code']);
        $this->assertSame('Descrição do fornecedor', $item['description']);

        // E nunca a identidade do cliente.
        $this->assertStringNotContainsString('CLIENT-CODE-1', json_encode($item));
        $this->assertStringNotContainsString('Descrição do cliente', json_encode($item));
    }

    public function test_purchase_order_keeps_a_deliberate_line_description(): void
    {
        $po = PurchaseOrder::factory()->create(['supplier_company_id' => $this->supplier->id]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $po->id,
            'product_id' => $this->product->id,
            'description' => 'Pintar de preto fosco neste pedido',
            'quantity' => 1,
            'unit_cost' => 1000,
            'sort_order' => 1,
        ]);

        $item = (new PurchaseOrderPdfTemplate($po->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('Pintar de preto fosco neste pedido', $item['description']);
    }

    public function test_rfq_pdf_and_excel_use_the_supplier_code(): void
    {
        $sq = SupplierQuotation::factory()->create([
            'company_id' => $this->supplier->id,
            'currency_code' => 'USD',
        ]);

        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $this->product->id,
            'description' => 'Internal Product Name',
            'quantity' => 5,
            'sort_order' => 1,
        ]);

        $pdfItem = (new RfqPdfTemplate($sq->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('SUP-CODE-9', $pdfItem['product_code']);
        $this->assertSame('张紧轮 120mm', $pdfItem['description']);

        $excelTemplate = new RfqExcelTemplate($sq->fresh());
        $rows = (new \ReflectionMethod($excelTemplate, 'getRows'))->invoke($excelTemplate);
        $excelRow = $rows[0];

        // Coluna SKU segue interna (o fornecedor devolve a planilha por ela);
        // a coluna Model passa a trazer o código do fornecedor.
        $this->assertSame('SKU-INT', $excelRow[1]);
        $this->assertSame('SUP-CODE-9', $excelRow[2]);
        $this->assertSame('张紧轮 120mm', $excelRow[3]);
    }

    public function test_production_schedule_template_round_trips_through_the_supplier_name(): void
    {
        $pi = ProformaInvoice::factory()->create(['company_id' => $this->client->id]);

        $piItem = ProformaInvoiceItem::create([
            'proforma_invoice_id' => $pi->id,
            'product_id' => $this->product->id,
            'description' => 'Internal Product Name',
            'quantity' => 100,
            'unit_price' => 1000,
            'unit' => 'pcs',
            'sort_order' => 1,
        ]);

        $schedule = ProductionSchedule::factory()->create([
            'proforma_invoice_id' => $pi->id,
            'supplier_company_id' => $this->supplier->id,
        ]);

        $path = app(GenerateProductionScheduleTemplate::class)->execute($schedule->fresh());

        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet()->toArray(null, true, false, false);
        @unlink($path);

        // Cabeçalho traz o fornecedor, não o cliente.
        $this->assertStringContainsString('Shenzhen Supplier', (string) $sheet[0][2]);
        $this->assertStringNotContainsString('Brazilian Client', (string) $sheet[0][2]);

        // Linha de dados nomeia o produto como o fornecedor o conhece…
        $this->assertSame('张紧轮 120mm', $sheet[3][0]);

        // …e a reimportação precisa casar por esse mesmo nome, senão a planilha
        // que já está com o fornecedor volta sem casar nenhuma linha.
        $matcher = new class($schedule->fresh())
        {
            use \App\Filament\Resources\ProductionSchedules\Concerns\ProductionScheduleHeaderActions;

            public function __construct(public $record) {}
        };

        $piItems = $pi->items()->with('product.companies')->get();
        $byName = $piItems->keyBy(fn ($item) => strtolower(trim($item->product?->name ?? $item->description ?? '')));

        $matched = (new \ReflectionMethod($matcher, 'matchPiItem'))
            ->invoke($matcher, '张紧轮 120mm', $byName, $piItems);

        $this->assertNotNull($matched, 'A planilha do fornecedor precisa casar pelo nome dele.');
        $this->assertSame($piItem->id, $matched->id);
    }
}
