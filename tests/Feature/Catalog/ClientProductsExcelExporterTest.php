<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Actions\FlexibleProductImportAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ClientProductsExcelExporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_client_products_with_original_and_client_data(): void
    {
        $client = Company::factory()->create(['name' => 'Eletro Brasil']);

        $productA = Product::factory()->create([
            'name' => 'AAA LED Panel 600x600',
            'sku' => 'SKU-A',
            'model_number' => 'MOD-A',
            'description' => 'Original description A',
            'hs_code' => '9405.10.99',
        ]);
        $productB = Product::factory()->create([
            'name' => 'BBB Solar Cable 6mm',
            'sku' => 'SKU-B',
            'model_number' => 'MOD-B',
        ]);

        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $productA->id,
            'role' => 'client',
            'external_code' => 'CLI-001',
            'external_name' => 'Painel LED do Cliente',
            'external_description' => 'Descrição do cliente A',
            'external_ncm' => '94051099',
            'unit_price' => Money::toMinor(12.5),
            'custom_price' => Money::toMinor(11.99),
            'currency_code' => 'USD',
        ]);

        // Client link with empty client-side data and no custom price.
        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $productB->id,
            'role' => 'client',
            'unit_price' => 0,
        ]);

        // Supplier-role link must NOT appear in the client report.
        $supplierOnly = Product::factory()->create(['name' => 'CCC Supplier Only']);
        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $supplierOnly->id,
            'role' => 'supplier',
            'unit_price' => 0,
        ]);

        // Fabricante: fornecedor preferencial vence o não-preferencial.
        $factoryA = Company::factory()->create(['name' => 'Shenzhen Factory A']);
        $factoryB = Company::factory()->create(['name' => 'Ningbo Factory B']);
        $productA->companies()->attach($factoryA->id, ['role' => 'supplier', 'is_preferred' => false]);
        $productA->companies()->attach($factoryB->id, ['role' => 'supplier', 'is_preferred' => true]);

        $path = (new ClientProductsExcelExporter)->export($client);

        $this->assertFileExists($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        // Title and headers (import-friendly labels matched by Quick Import auto-mapping).
        $this->assertSame('Produtos — Eletro Brasil', $sheet->getCell('A1')->getValue());
        $this->assertSame('Reference Code (SKU)', $sheet->getCell('B4')->getValue());
        $this->assertSame('Client Code', $sheet->getCell('G4')->getValue());
        $this->assertSame('Selling Price', $sheet->getCell('J4')->getValue());

        // Data rows start at row 5, ordered by product name (AAA before BBB).
        $this->assertSame('SKU-A', $sheet->getCell('B5')->getValue());
        $this->assertSame('AAA LED Panel 600x600', $sheet->getCell('C5')->getValue());
        $this->assertSame('MOD-A', $sheet->getCell('D5')->getValue());
        $this->assertSame('Original description A', $sheet->getCell('E5')->getValue());
        $this->assertSame('CLI-001', $sheet->getCell('G5')->getValue());
        $this->assertSame('Painel LED do Cliente', $sheet->getCell('H5')->getValue());
        $this->assertSame('Descrição do cliente A', $sheet->getCell('I5')->getValue());
        $this->assertEqualsWithDelta(12.5, $sheet->getCell('J5')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(11.99, $sheet->getCell('K5')->getValue(), 0.0001);
        $this->assertSame('USD', $sheet->getCell('L5')->getValue());

        $this->assertSame('NCM', $sheet->getCell('M4')->getValue());
        $this->assertSame('Fabricante', $sheet->getCell('N4')->getValue());
        // Coluna NCM traz o NCM do CLIENTE (pivot), não o hs_code do produto.
        $this->assertSame('94051099', $sheet->getCell('M5')->getValue());
        $this->assertSame('Ningbo Factory B', $sheet->getCell('N5')->getValue());

        // Second product: null client fields stay empty, no CI price.
        $this->assertSame('SKU-B', $sheet->getCell('B6')->getValue());
        $this->assertNull($sheet->getCell('G6')->getValue());
        $this->assertNull($sheet->getCell('K6')->getValue());

        // Supplier-only product excluded.
        $this->assertNull($sheet->getCell('B7')->getValue());

        unlink($path);
    }

    public function test_exports_valid_file_for_client_without_products(): void
    {
        $client = Company::factory()->create(['name' => 'Sem Produtos Ltda']);

        $path = (new ClientProductsExcelExporter)->export($client);

        $this->assertFileExists($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        $this->assertSame('Produtos — Sem Produtos Ltda', $sheet->getCell('A1')->getValue());
        $this->assertSame('Photo', $sheet->getCell('A4')->getValue());
        $this->assertNull($sheet->getCell('B5')->getValue());

        unlink($path);
    }

    /**
     * Round-trip guard: the Quick Import auto-mapper must map every editable
     * column of the exported report to the correct field, so the file can be
     * re-imported through "Quick Import" on the Products (Client) tab.
     */
    public function test_export_headers_are_auto_mapped_by_quick_import(): void
    {
        $client = Company::factory()->create(['name' => 'Eletro Brasil']);
        $product = Product::factory()->create(['name' => 'LED Panel']);
        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $product->id,
            'role' => 'client',
            'unit_price' => 0,
        ]);

        $path = (new ClientProductsExcelExporter)->export($client);

        $sheet = IOFactory::load($path)->getActiveSheet();
        $headerRow = array_map(
            fn (string $column) => (string) $sheet->getCell($column.'4')->getValue(),
            ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'],
        );

        $mapping = FlexibleProductImportAction::detectMapping($headerRow, 'client');

        // Column indexes are 0-based: B=1, C=2, D=3, G=6, H=7, I=8, J=9, K=10.
        $this->assertSame('1', $mapping['reference_code'] ?? null, 'SKU column must map to reference_code (product match key)');
        $this->assertSame('2', $mapping['product_name'] ?? null);
        $this->assertSame('3', $mapping['model_number'] ?? null);
        $this->assertSame('6', $mapping['external_code'] ?? null);
        $this->assertSame('7', $mapping['external_name'] ?? null);
        $this->assertSame('8', $mapping['external_description'] ?? null);
        $this->assertSame('9', $mapping['unit_price'] ?? null);
        $this->assertSame('10', $mapping['custom_price'] ?? null);

        unlink($path);
    }
}
