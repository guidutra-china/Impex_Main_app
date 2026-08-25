<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Excel\Templates\RfqExcelTemplate;
use App\Domain\Infrastructure\Pdf\Templates\RfqPdfTemplate;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RFQ (PDF e Excel) constroem o ProductIdentityResolver com
 * forSupplierCompany() — sem parent:, fornecedor não tem filial. Diferente
 * de PI/PO, o RFQ tem uma coluna de nome dedicada (item['description'] no
 * PDF, a coluna Description na planilha) que é literalmente identity->name
 * — não precisa do truque de desligar a descrição para observá-la.
 */
class RfqNamingPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const SUPPLIER_NAME = 'Nome do Fornecedor Para o Produto';

    private function makeSupplierWithProduct(array $companyAttributes = []): array
    {
        $supplier = Company::create(array_merge([
            'name' => 'RFQ Naming Supplier '.uniqid(),
            'status' => 'active',
        ], $companyAttributes));
        $supplier->companyRoles()->create(['role' => 'supplier']);

        $product = Product::factory()->create(['name' => 'Internal Product Name']);
        $product->companies()->attach($supplier->id, [
            'role' => 'supplier',
            'external_name' => self::SUPPLIER_NAME,
        ]);
        $product->load('companies');

        return [$supplier, $product];
    }

    private function makeSqWithItem(Company $supplier, Product $product): SupplierQuotation
    {
        $sq = SupplierQuotation::factory()->create([
            'company_id' => $supplier->id,
            'currency_code' => 'USD',
        ]);

        SupplierQuotationItem::create([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $product->id,
            'description' => 'Internal Product Name',
            'quantity' => 5,
            'sort_order' => 1,
        ]);

        return $sq;
    }

    public function test_pdf_system_name_source_prints_the_products_own_name_not_the_supplier_alias(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $sq = $this->makeSqWithItem($supplier, $product);

        $item = (new RfqPdfTemplate($sq->fresh(), 'en'))->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::SUPPLIER_NAME, $item['description']);
    }

    /**
     * Empresa nos padrões (COUNTERPARTY) — só o $options desta geração pede
     * SYSTEM. É este teste (PDF) que a mutação "derrubar overrides:" no
     * RfqPdfTemplate precisa quebrar.
     */
    public function test_pdf_modal_override_wins_over_the_companys_default_naming_preference(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct();

        $sq = $this->makeSqWithItem($supplier, $product);

        $item = (new RfqPdfTemplate($sq->fresh(), 'en', ['naming_name_source' => 'system']))
            ->getData()['items'][0];

        $this->assertSame('Internal Product Name', $item['description']);
        $this->assertNotSame(self::SUPPLIER_NAME, $item['description']);
    }

    public function test_excel_system_name_source_prints_the_products_own_name_not_the_supplier_alias(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct([
            'document_name_source' => DocumentNamingSource::SYSTEM,
        ]);

        $sq = $this->makeSqWithItem($supplier, $product);

        $template = new RfqExcelTemplate($sq->fresh());
        $row = (new \ReflectionMethod($template, 'getRows'))->invoke($template)[0];

        // Coluna 3 (índice 3) é a Description, que carrega identity->name.
        $this->assertSame('Internal Product Name', $row[3]);
        $this->assertNotSame(self::SUPPLIER_NAME, $row[3]);
    }

    /**
     * É este teste (Excel) que a mutação "derrubar overrides:" no
     * RfqExcelTemplate precisa quebrar.
     */
    public function test_excel_modal_override_wins_over_the_companys_default_naming_preference(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct();

        $sq = $this->makeSqWithItem($supplier, $product);

        $template = new RfqExcelTemplate($sq->fresh(), ['naming_name_source' => 'system']);
        $row = (new \ReflectionMethod($template, 'getRows'))->invoke($template)[0];

        $this->assertSame('Internal Product Name', $row[3]);
        $this->assertNotSame(self::SUPPLIER_NAME, $row[3]);
    }
}
