<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
use App\Domain\Catalog\Reports\ClientProductsReportImporter;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ClientProductsReportImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_client_links_from_edited_report(): void
    {
        $client = Company::factory()->create(['name' => 'Eletro Brasil']);

        $productA = Product::factory()->create(['name' => 'AAA LED Panel', 'sku' => 'SKU-A', 'hs_code' => '9405.10.99']);
        $productB = Product::factory()->create(['name' => 'BBB Solar Cable', 'sku' => 'SKU-B', 'hs_code' => '8544.49.00']);

        $linkA = CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $productA->id,
            'role' => 'client',
            'external_code' => 'OLD-CODE',
            'external_name' => 'Old Name',
            'unit_price' => Money::toMinor(10),
            'custom_price' => Money::toMinor(9),
            'currency_code' => 'USD',
        ]);
        $linkB = CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $productB->id,
            'role' => 'client',
            'external_code' => 'KEEP-OR-CLEAR',
            'unit_price' => Money::toMinor(5),
        ]);

        // Export, then edit the file like a user would.
        $path = (new ClientProductsExcelExporter)->export($client);
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        // Row 5 = product A (ordered by name): change client data and prices.
        $sheet->setCellValue('G5', 'NEW-CODE');
        $sheet->setCellValue('H5', 'New Client Name');
        $sheet->setCellValue('I5', 'New invoice description');
        $sheet->setCellValue('J5', 15.5);
        $sheet->setCellValue('K5', 14.25);
        $sheet->setCellValue('L5', 'BRL');
        $sheet->setCellValue('M5', '8501.31.20'); // NCM editado atualiza o produto

        // Row 6 = product B: blank cells must clear the stored values.
        $sheet->setCellValue('G6', '');
        $sheet->setCellValue('J6', '');
        $sheet->setCellValue('M6', ''); // NCM em branco NÃO limpa o produto

        // Extra row with unknown SKU must be skipped.
        $sheet->setCellValue('B7', 'SKU-UNKNOWN');
        $sheet->setCellValue('G7', 'IGNORED');

        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $stats = (new ClientProductsReportImporter)->import($client, $path);

        $this->assertSame(2, $stats['updated']);
        $this->assertSame(1, $stats['skipped']);

        $linkA->refresh();
        $this->assertSame('NEW-CODE', $linkA->external_code);
        $this->assertSame('New Client Name', $linkA->external_name);
        $this->assertSame('New invoice description', $linkA->external_description);
        $this->assertSame(Money::toMinor(15.5), $linkA->unit_price);
        $this->assertSame(Money::toMinor(14.25), $linkA->custom_price);
        $this->assertSame('BRL', $linkA->currency_code);

        $linkB->refresh();
        $this->assertNull($linkB->external_code);
        $this->assertSame(0, $linkB->unit_price);
        $this->assertNull($linkB->custom_price);

        $this->assertSame('8501.31.20', $productA->refresh()->hs_code);
        $this->assertSame('8544.49.00', $productB->refresh()->hs_code);

        unlink($path);
    }

    public function test_does_not_touch_supplier_links_or_other_clients(): void
    {
        $client = Company::factory()->create(['name' => 'Eletro Brasil']);
        $otherClient = Company::factory()->create(['name' => 'Outro Cliente']);

        $product = Product::factory()->create(['name' => 'LED Panel', 'sku' => 'SKU-A']);

        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $product->id,
            'role' => 'client',
            'external_code' => 'CLIENT-CODE',
            'unit_price' => 0,
        ]);
        $supplierLink = CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $product->id,
            'role' => 'supplier',
            'external_code' => 'SUPPLIER-CODE',
            'unit_price' => 0,
        ]);
        $otherClientLink = CompanyProduct::create([
            'company_id' => $otherClient->id,
            'product_id' => $product->id,
            'role' => 'client',
            'external_code' => 'OTHER-CODE',
            'unit_price' => 0,
        ]);

        $path = (new ClientProductsExcelExporter)->export($client);
        $spreadsheet = IOFactory::load($path);
        $spreadsheet->getActiveSheet()->setCellValue('G5', 'CHANGED');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        (new ClientProductsReportImporter)->import($client, $path);

        $this->assertSame('SUPPLIER-CODE', $supplierLink->refresh()->external_code);
        $this->assertSame('OTHER-CODE', $otherClientLink->refresh()->external_code);

        unlink($path);
    }

    public function test_rejects_spreadsheet_that_is_not_a_client_products_report(): void
    {
        $client = Company::factory()->create();

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setCellValue('A1', 'Random data');
        $path = storage_path('app/temp/not-a-report.xlsx');
        @mkdir(dirname($path), 0755, true);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        $this->expectException(InvalidArgumentException::class);

        try {
            (new ClientProductsReportImporter)->import($client, $path);
        } finally {
            unlink($path);
        }
    }
}
