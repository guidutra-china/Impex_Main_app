<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\ResolveSupplierQuotationDraft;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveSupplierQuotationDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_matches_existing_supplier_and_product_and_converts_price(): void
    {
        $supplier = Company::factory()->create(['name' => 'Nanjing Gencrea Equipment']);
        $product = Product::factory()->create(['reference_code' => 'AH223014']);

        $draft = [
            'fornecedor' => ['nome' => 'Nanjing Gencrea', 'currency_code' => 'USD'],
            'itens' => [
                ['part_no' => 'AH223014', 'description' => 'Existing', 'quantity' => 6, 'unit_price' => 100.00],
                ['part_no' => 'NEW-999', 'description' => 'Brand new', 'quantity' => 3, 'unit_price' => 12.50],
            ],
        ];

        $preview = (new ResolveSupplierQuotationDraft)->resolve($draft);

        $this->assertSame('existente', $preview['fornecedor']['status']);
        $this->assertSame($supplier->id, $preview['fornecedor']['company_id']);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($product->id, $preview['itens'][0]['product_id']);
        $this->assertSame(1000000, $preview['itens'][0]['unit_cost_minor']);

        $this->assertSame('novo', $preview['itens'][1]['status']);
        $this->assertNull($preview['itens'][1]['product_id']);
        $this->assertSame(125000, $preview['itens'][1]['unit_cost_minor']);

        $this->assertSame(2, $preview['resumo']['total_itens']);
        $this->assertSame(1, $preview['resumo']['produtos_existentes']);
        $this->assertSame(1, $preview['resumo']['produtos_novos']);
    }

    public function test_derives_unit_cost_from_line_total_and_folds_extras_into_notes(): void
    {
        // Dumbbell priced per kg ($0.94) but the line total ($796.65) is the real amount.
        $draft = [
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD', 'notes' => 'EXW'],
            'documento_total' => 1267.65, // items 796.65 + extras (750 - 279) = 1267.65 → reconciles
            'itens' => [
                ['part_no' => null, 'description' => 'Dumbbell 12.5kg', 'quantity' => 60, 'unit_price' => 0.94, 'line_total' => 796.65],
            ],
            'extras' => [
                ['descricao' => 'Customization Fee', 'valor' => 750.00],
                ['descricao' => 'Discount', 'valor' => -279.00],
            ],
        ];

        $preview = (new ResolveSupplierQuotationDraft)->resolve($draft);

        // Unit cost derived from line_total/qty, NOT from the per-kg unit_price.
        $this->assertSame((int) round(Money::toMinor(796.65) / 60), $preview['itens'][0]['unit_cost_minor']);
        $this->assertNotSame(Money::toMinor(0.94), $preview['itens'][0]['unit_cost_minor']);

        // Fees/discounts folded into notes (kept, not imported as items).
        $this->assertStringContainsString('EXW', $preview['cabecalho']['notes']);
        $this->assertStringContainsString('Customization Fee', $preview['cabecalho']['notes']);
        $this->assertStringContainsString('Discount', $preview['cabecalho']['notes']);

        // Document total surfaced and reconciles (no divergence).
        $this->assertSame('USD 1,267.65', $preview['resumo']['documento_total']);
        $this->assertFalse($preview['resumo']['divergencia']);
        $this->assertCount(2, $preview['resumo']['extras']);
    }

    public function test_flags_divergence_when_items_plus_extras_miss_document_total(): void
    {
        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'X', 'currency_code' => 'USD'],
            'documento_total' => 9999.00, // nowhere near the single $10 item
            'itens' => [
                ['description' => 'Item', 'quantity' => 1, 'unit_price' => 10.0, 'line_total' => 10.0],
            ],
        ]);

        $this->assertTrue($preview['resumo']['divergencia']);
    }

    public function test_assigns_existing_category_and_flags_uncategorized(): void
    {
        $category = Category::create(['name' => 'Gym Machines', 'slug' => 'gym-machines', 'sku_prefix' => 'GYM']);

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'X', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Treadmill', 'quantity' => 1, 'unit_price' => 100, 'categoria' => 'gym machines'],
                ['description' => 'Mystery', 'quantity' => 1, 'unit_price' => 10, 'categoria' => 'Nonexistent Cat'],
                ['description' => 'NoCat', 'quantity' => 1, 'unit_price' => 5],
            ],
        ]);

        $this->assertSame($category->id, $preview['itens'][0]['category_id']);
        $this->assertSame('Gym Machines', $preview['itens'][0]['category_name']);
        $this->assertNull($preview['itens'][1]['category_id']);
        $this->assertNull($preview['itens'][2]['category_id']);
        $this->assertSame(2, $preview['resumo']['produtos_sem_categoria']);
    }

    public function test_unknown_supplier_is_marked_new(): void
    {
        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Totally Unknown Co'],
            'itens' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1.0]],
        ]);

        $this->assertSame('novo', $preview['fornecedor']['status']);
        $this->assertNull($preview['fornecedor']['company_id']);
    }

    public function test_passes_supplier_contact_fields(): void
    {
        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => [
                'nome' => 'New Supplier Co',
                'phone' => '+86 123', 'email' => 'a@b.com', 'address_city' => 'Shenzhen',
                'legal_name' => 'New Supplier Co Ltd', 'tax_number' => 'TX1',
                'website' => 'x.com', 'address_street' => 'Rd 1', 'address_number' => '5',
                'address_complement' => 'F2', 'address_state' => 'GD', 'address_zip' => '518000', 'address_country' => 'CN',
            ],
            'itens' => [['description' => 'x', 'quantity' => 1, 'unit_price' => 1.0]],
        ]);

        $d = $preview['fornecedor_dados'];
        $this->assertSame('+86 123', $d['phone']);
        $this->assertSame('a@b.com', $d['email']);
        $this->assertSame('Shenzhen', $d['address_city']);
        $this->assertSame('New Supplier Co Ltd', $d['legal_name']);
    }
}
