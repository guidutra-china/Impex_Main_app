<?php

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\Actions\CreateDraftProductForSupplierAction;
use App\Domain\Catalog\Enums\ProductStatus;
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

        $this->assertSame(250, strlen($product->name));
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
}
