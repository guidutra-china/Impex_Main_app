<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\SupplierQuotations\Actions\ImportSupplierQuotationAction;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImportSupplierQuotationActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['create-supplier-quotations', 'create-companies', 'create-products'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        Storage::fake('local');
    }

    private function previewWithNewSupplierAndProduct(): array
    {
        return [
            'fornecedor' => ['status' => 'novo', 'company_id' => null, 'nome' => 'Nova Fornecedora Ltd'],
            'cabecalho' => ['currency_code' => 'USD', 'incoterm' => 'FOB', 'lead_time_days' => 30, 'moq' => null, 'valid_until' => null, 'supplier_reference' => 'SR-1', 'notes' => null],
            'itens' => [[
                'status' => 'novo', 'product_id' => null, 'part_no' => 'AH223014', 'description' => 'Chaffer arm',
                'quantity' => 6, 'unit' => 'pcs', 'unit_cost_minor' => 10000, 'specifications' => 'WEIGHT 5kg', 'moq' => null, 'lead_time_days' => null, 'notes' => null,
            ]],
            'resumo' => ['total_itens' => 1, 'produtos_existentes' => 0, 'produtos_novos' => 1, 'total_estimado' => 'USD 600.00'],
        ];
    }

    private function fakeFile(): string
    {
        $path = storage_path('app/ai-imports-test.xlsx');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, 'fake');

        return $path;
    }

    public function test_creates_supplier_products_and_quotation_in_one_go(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $sq = (new ImportSupplierQuotationAction)($this->previewWithNewSupplierAndProduct(), $user, $this->fakeFile());

        $this->assertInstanceOf(SupplierQuotation::class, $sq);
        $this->assertDatabaseHas('companies', ['name' => 'Nova Fornecedora Ltd']);
        $this->assertDatabaseHas('products', ['reference_code' => 'AH223014']);
        $this->assertSame(1, $sq->items()->count());
        $item = $sq->items()->first();
        $this->assertSame(10000, (int) $item->unit_cost);
        $this->assertSame(60000, (int) $item->total_cost);

        $product = Product::where('reference_code', 'AH223014')->first();
        $this->assertTrue($product->suppliers()->where('companies.id', $sq->company_id)->exists());

        $this->assertSame(1, $sq->documents()->count());
    }

    public function test_new_product_uses_matched_category_and_prefixed_sku(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $category = Category::create(['name' => 'Rack', 'slug' => 'rack', 'sku_prefix' => 'RCK']);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['itens'][0]['category_id'] = $category->id;

        // Stub the SKU generator: execute() uses MySQL-only SQL (SUBSTRING_INDEX) that
        // can't run on the sqlite test DB; here we only verify the category branch is taken.
        $skuGenerator = new class extends \App\Domain\Catalog\Actions\GenerateProductSkuAction
        {
            public function execute(int $categoryId): string
            {
                return 'RCK-00001';
            }
        };

        (new ImportSupplierQuotationAction($skuGenerator))($preview, $user, $this->fakeFile());

        $product = Product::where('reference_code', 'AH223014')->first();
        $this->assertSame($category->id, $product->category_id);
        $this->assertStringStartsWith('RCK-', $product->sku);
    }

    public function test_item_without_unit_falls_back_to_default(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['itens'][0]['unit'] = null;

        $sq = (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());

        $this->assertSame('pcs', $sq->items()->first()->unit);
    }

    public function test_requires_create_companies_when_supplier_is_new(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-products']);

        $this->expectException(AuthorizationException::class);
        (new ImportSupplierQuotationAction)($this->previewWithNewSupplierAndProduct(), $user, $this->fakeFile());
    }

    public function test_requires_base_permission(): void
    {
        $user = User::factory()->create();

        $this->expectException(AuthorizationException::class);
        (new ImportSupplierQuotationAction)($this->previewWithNewSupplierAndProduct(), $user, $this->fakeFile());
    }
}
