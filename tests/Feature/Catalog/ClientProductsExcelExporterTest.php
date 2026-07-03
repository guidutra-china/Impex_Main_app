<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Reports\ClientProductsExcelExporter;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
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

        $path = (new ClientProductsExcelExporter)->export($client);

        $this->assertFileExists($path);

        $sheet = IOFactory::load($path)->getActiveSheet();

        // Title and headers.
        $this->assertSame('Produtos — Eletro Brasil', $sheet->getCell('A1')->getValue());
        $this->assertSame('SKU', $sheet->getCell('B4')->getValue());
        $this->assertSame('Código do Cliente', $sheet->getCell('G4')->getValue());
        $this->assertSame('Preço de Venda', $sheet->getCell('J4')->getValue());

        // Data rows start at row 5, ordered by product name (AAA before BBB).
        $this->assertSame('SKU-A', $sheet->getCell('B5')->getValue());
        $this->assertSame('MOD-A', $sheet->getCell('C5')->getValue());
        $this->assertSame('AAA LED Panel 600x600', $sheet->getCell('D5')->getValue());
        $this->assertSame('Original description A', $sheet->getCell('E5')->getValue());
        $this->assertSame('CLI-001', $sheet->getCell('G5')->getValue());
        $this->assertSame('Painel LED do Cliente', $sheet->getCell('H5')->getValue());
        $this->assertSame('Descrição do cliente A', $sheet->getCell('I5')->getValue());
        $this->assertEqualsWithDelta(12.5, $sheet->getCell('J5')->getValue(), 0.0001);
        $this->assertEqualsWithDelta(11.99, $sheet->getCell('K5')->getValue(), 0.0001);
        $this->assertSame('USD', $sheet->getCell('L5')->getValue());

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
        $this->assertSame('Foto', $sheet->getCell('A4')->getValue());
        $this->assertNull($sheet->getCell('B5')->getValue());

        unlink($path);
    }
}
