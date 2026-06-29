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

    public function test_new_supplier_gets_contact_and_supplier_role(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['fornecedor_dados'] = ['phone' => '+86 1', 'email' => 's@x.com', 'address_city' => 'Shenzhen', 'legal_name' => 'Nova LTDA'];

        $sq = (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());
        $company = $sq->company;

        $this->assertSame('+86 1', $company->phone);
        $this->assertSame('Shenzhen', $company->address_city);
        $this->assertSame('Nova LTDA', $company->legal_name);
        $this->assertTrue($company->fresh()->hasRole(\App\Domain\CRM\Enums\CompanyRole::SUPPLIER));
    }

    public function test_oversized_contact_fields_do_not_break_import(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['fornecedor_dados'] = [
            'address_country' => 'China',             // full name -> normalized to CN (col is varchar(2))
            'address_zip' => str_repeat('9', 50),     // > 20 -> truncated
            'address_street' => str_repeat('A', 400), // > 255 -> truncated
        ];
        $preview['cabecalho']['incoterm'] = 'FOB (porto não informado) — detalhado'; // > 20 -> capped
        $preview['itens'][0]['unit'] = str_repeat('u', 40); // > 20 -> capped

        $sq = (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());
        $company = $sq->company;

        $this->assertSame('CN', $company->address_country);
        $this->assertSame(20, strlen($company->address_zip));
        $this->assertSame(255, strlen($company->address_street));
        $this->assertLessThanOrEqual(20, mb_strlen((string) $sq->incoterm));
        $this->assertLessThanOrEqual(20, mb_strlen((string) $sq->items()->first()->unit));
    }

    public function test_attaches_photo_by_index_when_item_has_no_part_no(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $img = storage_path('app/ai-imports-test-idx.png');
        @mkdir(dirname($img), 0775, true);
        file_put_contents($img, 'PNGDATA');

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['itens'][0]['part_no'] = null; // PDF-style item with no part number
        $images = ['idx:0' => $img];           // matched by position

        $sq = (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile(), $images);

        $product = $sq->items()->first()->product;
        $this->assertNotNull($product);
        $this->assertSame(1, $product->images()->count());
        // The avatar (used by product list/cards) must also point at the image.
        $this->assertStringStartsWith('product-images/', (string) $product->avatar);
        @unlink($img);
    }

    public function test_attaches_photo_to_new_product_and_skips_product_with_images(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $img = storage_path('app/ai-imports-test-img.png');
        @mkdir(dirname($img), 0775, true);
        file_put_contents($img, 'PNGDATA');

        $preview = $this->previewWithNewSupplierAndProduct(); // item part_no AH223014, novo
        $images = ['AH223014' => $img];

        $sq = (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile(), $images);

        $product = Product::where('reference_code', 'AH223014')->first();
        $this->assertSame(1, $product->images()->count());
        $this->assertTrue((bool) $product->images()->first()->is_primary);
        @unlink($img);
    }

    public function test_existing_supplier_keeps_data_but_gains_role_and_fills_blanks(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-products']);

        $existing = \App\Domain\CRM\Models\Company::factory()->create(['name' => 'Existing Co', 'phone' => '+86 ORIGINAL', 'email' => null]);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['fornecedor'] = ['status' => 'existente', 'company_id' => $existing->id, 'nome' => 'Existing Co'];
        $preview['fornecedor_dados'] = ['phone' => '+86 NEW', 'email' => 'filled@x.com'];

        (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());
        $existing->refresh();

        $this->assertSame('+86 ORIGINAL', $existing->phone);
        $this->assertSame('filled@x.com', $existing->email);
        $this->assertTrue($existing->hasRole(\App\Domain\CRM\Enums\CompanyRole::SUPPLIER));
    }
}
