<?php

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Actions\CreateDraftProductForSupplierAction;
use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateDraftProductForSupplierActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_draft_product_and_links_supplier_pivot(): void
    {
        $supplier = Company::factory()->create();

        $product = (new CreateDraftProductForSupplierAction)->execute(
            description: 'Adjustable dumbbell 24kg',
            supplier: $supplier,
            externalCode: 'YR-0706',
        );

        $this->assertSame('Adjustable dumbbell 24kg', $product->name);
        $this->assertSame(ProductStatus::DRAFT, $product->status);
        $this->assertSame('YR-0706', $product->reference_code);
        $this->assertStringStartsWith('DRF-', $product->sku);
        // O código do fornecedor vive no pivot, nunca em model_number.
        $this->assertNull($product->model_number);

        $pivot = $product->suppliers()->where('companies.id', $supplier->id)->first();
        $this->assertNotNull($pivot);
        $this->assertSame('YR-0706', $pivot->pivot->external_code);
    }

    public function test_truncates_long_description_to_250_chars(): void
    {
        $supplier = Company::factory()->create();

        $product = (new CreateDraftProductForSupplierAction)->execute(
            description: str_repeat('a', 400),
            supplier: $supplier,
        );

        $this->assertSame(250, mb_strlen($product->name));
        $this->assertNull($product->reference_code);
    }

    public function test_link_supplier_does_not_overwrite_existing_external_code(): void
    {
        $supplier = Company::factory()->create();
        $action = new CreateDraftProductForSupplierAction;

        $product = $action->execute('Bench press', $supplier, 'CODE-A');
        $action->linkSupplier($product, $supplier, 'CODE-B');

        $pivot = $product->fresh()->suppliers()->where('companies.id', $supplier->id)->first();
        $this->assertSame('CODE-A', $pivot->pivot->external_code);
        $this->assertSame(1, $product->fresh()->suppliers()->count());
    }

    public function test_link_supplier_does_not_touch_client_pivot_row_of_same_company(): void
    {
        // Uma mesma empresa pode ser cliente E fornecedora do mesmo produto —
        // linkSupplier() não pode gravar na linha errada do pivot.
        $company = Company::factory()->create();
        $product = Product::factory()->create();

        $product->companies()->attach($company->id, [
            'role' => 'client',
            'external_code' => 'CLIENT-CODE',
        ]);
        $product->companies()->attach($company->id, [
            'role' => 'supplier',
            'external_code' => null,
        ]);

        (new CreateDraftProductForSupplierAction)->linkSupplier($product, $company, 'SUPPLIER-CODE');

        $clientPivot = $product->fresh()->clients()->where('companies.id', $company->id)->first();
        $this->assertSame('CLIENT-CODE', $clientPivot->pivot->external_code);

        $supplierPivot = $product->fresh()->suppliers()->where('companies.id', $company->id)->first();
        $this->assertSame('SUPPLIER-CODE', $supplierPivot->pivot->external_code);
    }

    public function test_execute_with_category_id_uses_category_prefixed_sku(): void
    {
        $supplier = Company::factory()->create();
        $category = Category::create(['name' => 'Rack', 'slug' => 'rack', 'sku_prefix' => 'RCK']);

        // execute() do gerador real usa SQL exclusivo de MySQL (SUBSTRING_INDEX),
        // que não roda no sqlite dos testes; aqui só validamos qual caminho é chamado.
        $skuGenerator = new class extends GenerateProductSkuAction
        {
            public function execute(int $categoryId): string
            {
                return 'RCK-00001';
            }
        };

        $product = (new CreateDraftProductForSupplierAction($skuGenerator))->execute(
            description: 'Squat rack',
            supplier: $supplier,
            categoryId: $category->id,
        );

        $this->assertSame('RCK-00001', $product->sku);
        $this->assertSame($category->id, $product->category_id);
    }

    public function test_link_supplier_creates_separate_pivot_rows_for_different_suppliers(): void
    {
        $firstSupplier = Company::factory()->create();
        $secondSupplier = Company::factory()->create();
        $action = new CreateDraftProductForSupplierAction;

        $product = $action->execute('Bench press', $firstSupplier, 'CODE-A');
        $action->linkSupplier($product, $secondSupplier, 'CODE-B');

        $this->assertSame(2, $product->fresh()->suppliers()->count());

        $firstPivot = $product->fresh()->suppliers()->where('companies.id', $firstSupplier->id)->first();
        $secondPivot = $product->fresh()->suppliers()->where('companies.id', $secondSupplier->id)->first();

        $this->assertSame('CODE-A', $firstPivot->pivot->external_code);
        $this->assertSame('CODE-B', $secondPivot->pivot->external_code);
    }
}
