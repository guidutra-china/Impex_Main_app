<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\Support\NameNormalizer;
use App\Domain\Catalog\Actions\CreateDraftProductForSupplierAction;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Models\Document;
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

    public function test_links_created_sq_to_inquiry_when_entry_context_given(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $inquiry = \App\Domain\Inquiries\Models\Inquiry::factory()->create(['description' => 'Peças de trator John Deere']);

        $sq = (new ImportSupplierQuotationAction)($this->previewWithNewSupplierAndProduct(), $user, $this->fakeFile(), [], $inquiry->id);

        $this->assertSame($inquiry->id, $sq->inquiry_id);
        $this->assertSame('Peças de trator John Deere', $sq->description);

        // Sem contexto, continua sem vínculo.
        $freePreview = $this->previewWithNewSupplierAndProduct();
        $freePreview['fornecedor']['nome'] = 'Outra Fornecedora Ltd';
        $freePreview['itens'][0]['part_no'] = 'AH223015';
        $free = (new ImportSupplierQuotationAction)($freePreview, $user, $this->fakeFile());
        $this->assertNull($free->inquiry_id);

        // Inquiry inexistente falha antes de gravar qualquer coisa.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        (new ImportSupplierQuotationAction)($this->previewWithNewSupplierAndProduct(), $user, $this->fakeFile(), [], 999999);
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
        // Product creation now lives in CreateDraftProductForSupplierAction, so the stub
        // is wired through that action rather than directly into the import action.
        $skuGenerator = new class extends \App\Domain\Catalog\Actions\GenerateProductSkuAction
        {
            public function execute(int $categoryId): string
            {
                return 'RCK-00001';
            }
        };
        $productCreator = new CreateDraftProductForSupplierAction($skuGenerator);

        (new ImportSupplierQuotationAction(productCreator: $productCreator))($preview, $user, $this->fakeFile());

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

    public function test_rollback_after_file_copy_leaves_no_orphan_file(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        // Fail the documents()->create() that runs right after the Storage::put,
        // forcing the transaction to roll back with the file already copied.
        Document::creating(fn () => throw new \RuntimeException('doc create failed'));

        try {
            (new ImportSupplierQuotationAction)($this->previewWithNewSupplierAndProduct(), $user, $this->fakeFile());
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertSame('doc create failed', $e->getMessage());
        }

        $this->assertDatabaseCount('supplier_quotations', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_confirm_learns_aliases_for_items_with_product(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);
        $existing = Product::factory()->create(['name' => 'Hexagonal Dumbbell 5kg']);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['itens'][] = [
            'status' => 'existente',
            'product_id' => $existing->id,
            'part_no' => null,
            'description' => 'Halter hexagonal — 5kg',
            'quantity' => 2,
            'unit' => 'pcs',
            'unit_cost_minor' => 35000,
            'category_id' => null,
        ];

        (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());

        $this->assertDatabaseHas('product_import_aliases', [
            'product_id' => $existing->id,
            'alias_normalized' => NameNormalizer::normalize('Halter hexagonal — 5kg'),
            'source' => 'import_confirm',
        ]);

        // O item "novo" do preview base criou produto draft — o alias dele
        // também é gravado (aponta para o produto recém-criado):
        $this->assertSame(count($preview['itens']), ProductImportAlias::count());
    }

    public function test_link_supplier_does_not_overwrite_client_pivot_row_of_same_company(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $product = Product::factory()->create();
        $company = \App\Domain\CRM\Models\Company::factory()->create(['name' => 'Existing Co']);

        // A mesma empresa já tem uma linha `client` com o próprio código, e uma
        // linha `supplier` ainda sem external_code — cenário em que o bug do
        // fallback de pivot (company_id, product_id) atingia a linha errada.
        $product->companies()->attach($company->id, ['role' => 'client', 'external_code' => 'CLIENT-CODE']);
        $product->companies()->attach($company->id, ['role' => 'supplier', 'external_code' => null]);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['fornecedor'] = ['status' => 'existente', 'company_id' => $company->id, 'nome' => 'Existing Co'];
        $preview['itens'][0] = [
            'status' => 'existente', 'product_id' => $product->id, 'part_no' => 'SUP-CODE',
            'description' => $product->name, 'quantity' => 1, 'unit' => 'pcs',
            'unit_cost_minor' => 1000, 'specifications' => null, 'moq' => null,
            'lead_time_days' => null, 'notes' => null,
        ];

        (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());

        $clientPivot = $product->clients()->where('companies.id', $company->id)->first();
        $supplierPivot = $product->suppliers()->where('companies.id', $company->id)->first();

        $this->assertSame('CLIENT-CODE', $clientPivot->pivot->external_code);
        $this->assertSame('SUP-CODE', $supplierPivot->pivot->external_code);
    }

    public function test_new_supplier_whose_name_matches_existing_company_is_reused(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['create-supplier-quotations', 'create-companies', 'create-products']);

        $existing = \App\Domain\CRM\Models\Company::factory()->create(['name' => 'Nova Fornecedora Ltd']);

        $preview = $this->previewWithNewSupplierAndProduct();
        $preview['fornecedor'] = ['status' => 'novo', 'company_id' => null, 'nome' => ' NOVA  fornecedora ltd'];

        $sq = (new ImportSupplierQuotationAction)($preview, $user, $this->fakeFile());

        $this->assertSame($existing->id, $sq->company_id);
        $this->assertSame(1, \App\Domain\CRM\Models\Company::count());
        $this->assertTrue($existing->fresh()->hasRole(\App\Domain\CRM\Enums\CompanyRole::SUPPLIER));
    }
}
