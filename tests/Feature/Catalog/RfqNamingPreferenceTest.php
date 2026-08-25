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

    private function makeSqWithItem(Company $supplier, Product $product, array $itemAttributes = []): SupplierQuotation
    {
        $sq = SupplierQuotation::factory()->create([
            'company_id' => $supplier->id,
            'currency_code' => 'USD',
        ]);

        SupplierQuotationItem::create(array_merge([
            'supplier_quotation_id' => $sq->id,
            'product_id' => $product->id,
            'description' => 'Internal Product Name',
            'quantity' => 5,
            'sort_order' => 1,
        ], $itemAttributes));

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

    /**
     * Coluna 4 (índice 4) é a Specifications, que carrega
     * `$identity->description ?: ($item->specifications ?? $product?->description ?? '')`.
     * Sem o guard de descriptionHidden, esconder a descrição fazia essa
     * célula cair direto no texto de specifications da linha — nunca ficava
     * vazia, só trocava de fonte. 'Line specifications text' é o fallback
     * não-vazio que expõe isso.
     */
    public function test_excel_show_description_false_empties_the_specifications_column(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct([
            'document_show_description' => false,
        ]);

        $sq = $this->makeSqWithItem($supplier, $product, [
            'specifications' => 'Line specifications text',
        ]);

        $template = new RfqExcelTemplate($sq->fresh());
        $row = (new \ReflectionMethod($template, 'getRows'))->invoke($template)[0];

        $this->assertSame('', $row[4]);
    }

    /**
     * Pina o PDF: 'specifications' honra a mesma preferência (a review final
     * havia classificado este site como já correto, mas ele usa exatamente o
     * mesmo padrão `?:` sem guard que o Excel — corrigido junto). Mesmo
     * fallback não-vazio do teste do Excel, mesma prova.
     */
    public function test_pdf_show_description_false_empties_the_specifications_field(): void
    {
        [$supplier, $product] = $this->makeSupplierWithProduct([
            'document_show_description' => false,
        ]);

        $sq = $this->makeSqWithItem($supplier, $product, [
            'specifications' => 'Line specifications text',
        ]);

        $item = (new RfqPdfTemplate($sq->fresh(), 'en'))->getData()['items'][0];

        $this->assertNull($item['specifications']);
    }
}
