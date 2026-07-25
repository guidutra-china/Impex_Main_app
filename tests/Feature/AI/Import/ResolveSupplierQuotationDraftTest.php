<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\ResolveSupplierQuotationDraft;
use App\Domain\AI\Import\Support\NameNormalizer;
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

    public function test_name_fallback_ignores_punctuation_and_spacing_differences(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $dumbbell = Product::factory()->create(['name' => 'Dumbbell hexagonal 5kg', 'model_number' => null, 'reference_code' => null]);
        $dumbbell->companies()->attach($supplier->id, ['role' => 'supplier']);

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [
                // Mesmo produto, formatação diferente (travessão da expansão de variantes).
                ['description' => 'Dumbbell Hexagonal — 5kg', 'quantity' => 10, 'unit_price' => 3.5],
            ],
        ]);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($dumbbell->id, $preview['itens'][0]['product_id']);
    }

    public function test_passes_through_description_inferred_flag_and_matched_product_name(): void
    {
        $product = Product::factory()->create(['name' => 'Triangle Handle', 'reference_code' => 'TH-100']);

        $draft = [
            'fornecedor' => ['nome' => 'Qualquer', 'currency_code' => 'USD'],
            'itens' => [
                ['part_no' => 'TH-100', 'description' => 'Puxador triangular', 'quantity' => 1, 'unit_price' => 5.0, 'descricao_inferida' => true],
                ['part_no' => 'XX-1', 'description' => 'Outro', 'quantity' => 1, 'unit_price' => 1.0],
            ],
        ];

        $preview = (new ResolveSupplierQuotationDraft)->resolve($draft);

        $this->assertTrue($preview['itens'][0]['description_inferred']);
        $this->assertSame('Triangle Handle', $preview['itens'][0]['product_name']);
        $this->assertFalse($preview['itens'][1]['description_inferred']);
        $this->assertNull($preview['itens'][1]['product_name']);
    }

    public function test_matches_by_supplier_scoped_product_name_when_document_has_no_part_numbers(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $other = Company::factory()->create(['name' => 'Outra Fábrica']);

        $rack = Product::factory()->create(['name' => 'Vertical Dumbbell Rack', 'model_number' => null, 'reference_code' => null]);
        $rack->companies()->attach($supplier->id, ['role' => 'supplier']);

        // Homônimo de OUTRO fornecedor não pode ser usado.
        $foreignTwin = Product::factory()->create(['name' => 'Weight plate 5kg']);
        $foreignTwin->companies()->attach($other->id, ['role' => 'supplier']);

        // Nome duplicado DENTRO do fornecedor → match ambíguo, fica novo.
        foreach (range(1, 2) as $i) {
            $dup = Product::factory()->create(['name' => 'Dumbbell 15kg']);
            $dup->companies()->attach($supplier->id, ['role' => 'supplier']);
        }

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Vertical  dumbbell rack', 'quantity' => 24, 'unit_price' => 33.58], // case/espaços normalizados
                ['description' => 'Weight plate 5kg', 'quantity' => 240, 'unit_price' => 0.97],
                ['description' => 'Dumbbell 15kg', 'quantity' => 48, 'unit_price' => 0.94],
            ],
        ]);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($rack->id, $preview['itens'][0]['product_id']);

        $this->assertSame('novo', $preview['itens'][1]['status'], 'homônimo de outro fornecedor não casa');
        $this->assertSame('novo', $preview['itens'][2]['status'], 'nome duplicado no fornecedor não casa');
    }

    public function test_alias_layer_matches_learned_description(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $product = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null]);
        $product->companies()->attach($supplier->id, ['role' => 'supplier']);

        ProductImportAlias::create([
            'company_id' => $supplier->id,
            'product_id' => $product->id,
            'alias' => 'Halter hexagonal emborrachado — 5kg',
            'alias_normalized' => NameNormalizer::normalize('Halter hexagonal emborrachado — 5kg'),
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Halter Hexagonal Emborrachado 5kg', 'quantity' => 10, 'unit_price' => 3.5],
            ],
        ]);

        $this->assertSame('existente', $preview['itens'][0]['status']);
        $this->assertSame($product->id, $preview['itens'][0]['product_id']);
        $this->assertSame('alias', $preview['itens'][0]['match_source']);
    }

    public function test_exact_name_beats_alias(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $byName = Product::factory()->create(['name' => 'Dumbbell 10kg', 'reference_code' => null, 'model_number' => null]);
        $byName->companies()->attach($supplier->id, ['role' => 'supplier']);
        $byAlias = Product::factory()->create(['name' => 'Outro produto']);

        // Alias conflitante para a MESMA descrição — o nome exato deve vencer.
        ProductImportAlias::create([
            'company_id' => $supplier->id,
            'product_id' => $byAlias->id,
            'alias' => 'Dumbbell 10kg',
            'alias_normalized' => NameNormalizer::normalize('Dumbbell 10kg'),
            'source' => 'backfill',
            'last_confirmed_at' => now(),
        ]);

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [['description' => 'Dumbbell — 10kg', 'quantity' => 1, 'unit_price' => 1.0]],
        ]);

        $this->assertSame($byName->id, $preview['itens'][0]['product_id']);
        $this->assertSame('name', $preview['itens'][0]['match_source']);
    }

    public function test_alias_pointing_to_soft_deleted_product_falls_through(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $product = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null]);
        $product->companies()->attach($supplier->id, ['role' => 'supplier']);

        ProductImportAlias::create([
            'company_id' => $supplier->id,
            'product_id' => $product->id,
            'alias' => 'Halter hexagonal emborrachado — 5kg',
            'alias_normalized' => NameNormalizer::normalize('Halter hexagonal emborrachado — 5kg'),
            'source' => 'import_confirm',
            'last_confirmed_at' => now(),
        ]);

        $product->delete();

        $preview = (new ResolveSupplierQuotationDraft)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Halter Hexagonal Emborrachado 5kg', 'quantity' => 10, 'unit_price' => 3.5],
            ],
        ]);

        $this->assertSame('novo', $preview['itens'][0]['status']);
        $this->assertNull($preview['itens'][0]['product_id']);
        $this->assertNull($preview['itens'][0]['match_source']);
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

    /**
     * @param  array<int,int>  $suggestions
     */
    private function resolverWithSuggester(array $suggestions, bool $throw = false): ResolveSupplierQuotationDraft
    {
        $suggester = new class($suggestions, $throw) extends \App\Domain\AI\Import\ProductMatchSuggester
        {
            public function __construct(private readonly array $suggestions, private readonly bool $throw)
            {
                parent::__construct();
            }

            public function suggest(array $items, array $catalog): array
            {
                if ($this->throw) {
                    throw new \RuntimeException('API down');
                }

                return array_intersect_key($this->suggestions, $items);
            }
        };

        return new ResolveSupplierQuotationDraft($suggester);
    }

    public function test_ai_layer_suggests_only_for_unmatched_items(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $exact = Product::factory()->create(['name' => 'Weight plate 5kg', 'reference_code' => null, 'model_number' => null]);
        $exact->companies()->attach($supplier->id, ['role' => 'supplier']);
        $suggested = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null]);
        $suggested->companies()->attach($supplier->id, ['role' => 'supplier']);

        $preview = $this->resolverWithSuggester([1 => $suggested->id])->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [
                ['description' => 'Weight plate 5kg', 'quantity' => 1, 'unit_price' => 1.0],
                ['description' => 'Halter hexagonal emborrachado 5kg', 'quantity' => 1, 'unit_price' => 2.0],
            ],
        ]);

        $this->assertSame('name', $preview['itens'][0]['match_source']);
        $this->assertSame($suggested->id, $preview['itens'][1]['product_id']);
        $this->assertSame('existente', $preview['itens'][1]['status']);
        $this->assertSame('ai', $preview['itens'][1]['match_source']);
    }

    public function test_ai_failure_degrades_silently_to_novo(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $product = Product::factory()->create(['name' => 'Weight plate 5kg']);
        $product->companies()->attach($supplier->id, ['role' => 'supplier']);

        $preview = $this->resolverWithSuggester([], throw: true)->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [['description' => 'Coisa desconhecida', 'quantity' => 1, 'unit_price' => 1.0]],
        ]);

        $this->assertSame('novo', $preview['itens'][0]['status']);
        $this->assertNull($preview['itens'][0]['product_id']);
        $this->assertNull($preview['itens'][0]['match_source']);
    }

    public function test_ai_matches_count_as_existing_in_summary(): void
    {
        $supplier = Company::factory()->create(['name' => 'Hebei Yangrun Sports Equipment Co., Ltd.']);
        $suggested = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg']);
        $suggested->companies()->attach($supplier->id, ['role' => 'supplier']);

        $preview = $this->resolverWithSuggester([0 => $suggested->id])->resolve([
            'fornecedor' => ['nome' => 'Hebei Yangrun', 'currency_code' => 'USD'],
            'itens' => [['description' => 'Halter hexagonal emborrachado 5kg', 'quantity' => 1, 'unit_price' => 2.0]],
        ]);

        $this->assertSame(1, $preview['resumo']['produtos_existentes']);
        $this->assertSame(0, $preview['resumo']['produtos_novos']);
    }
}
