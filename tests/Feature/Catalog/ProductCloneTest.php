<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Filament\Resources\Catalog\Products\Pages\EditProduct;
use App\Filament\Resources\Catalog\Products\Pages\ListProducts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression guard for the product Clone (ReplicateAction).
 *
 * reference_code carries a unique index (products_reference_code_unique). The
 * replicate action copies the original $attributes, so without excluding
 * reference_code the cloned insert violates that constraint. In production
 * (APP_DEBUG=false) the exception is hidden behind a generic notification, so
 * this test reproduces the failing path with a real reference_code present.
 */
class ProductCloneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->actingAs($user);
        Gate::before(fn () => true);
    }

    public function test_cloning_a_product_with_a_reference_code_does_not_violate_unique_index(): void
    {
        $original = Product::factory()->create([
            'name' => 'LED Panel 600x600',
            'reference_code' => 'REF-UNIQUE-001',
            'status' => ProductStatus::ACTIVE,
        ]);

        Livewire::test(ListProducts::class)
            ->callTableAction('replicate', $original)
            ->assertHasNoTableActionErrors();

        $clone = Product::query()
            ->where('id', '!=', $original->id)
            ->where('name', 'LED Panel 600x600 (Copy)')
            ->firstOrFail();

        // Unique columns must be cleared so the insert and future edits stay valid.
        $this->assertNull($clone->reference_code);
        $this->assertNotSame($original->sku, $clone->sku);
        $this->assertNotNull($clone->sku);
        $this->assertSame(ProductStatus::DRAFT, $clone->status);

        // Original is untouched.
        $this->assertSame('REF-UNIQUE-001', $original->fresh()->reference_code);
    }

    /**
     * external_code/external_name/external_description são o código e nome do
     * produto NO cliente/fornecedor — específicos de cada produto. Copiá-los no
     * clone fazia a Commercial Invoice / Packing List repetir o model number do
     * produto original em todos os clones (o PDF prioriza external_code sobre
     * product.model_number).
     */
    public function test_table_clone_does_not_copy_external_identity_fields_from_company_pivot(): void
    {
        [$original, $client] = $this->createProductLinkedToClient();

        Livewire::test(ListProducts::class)
            ->callTableAction('replicate', $original)
            ->assertHasNoTableActionErrors();

        $clone = Product::query()
            ->where('id', '!=', $original->id)
            ->where('name', 'Lens 30 8H1 (Copy)')
            ->firstOrFail();

        $this->assertClonedPivotHasNoExternalIdentity($clone, $client, $original);
    }

    public function test_edit_page_clone_does_not_copy_external_identity_fields_from_company_pivot(): void
    {
        [$original, $client] = $this->createProductLinkedToClient();

        Livewire::test(EditProduct::class, ['record' => $original->getRouteKey()])
            ->callAction('replicate')
            ->assertHasNoActionErrors();

        $clone = Product::query()
            ->where('id', '!=', $original->id)
            ->where('name', 'Lens 30 8H1 (Copy)')
            ->firstOrFail();

        $this->assertClonedPivotHasNoExternalIdentity($clone, $client, $original);
    }

    /**
     * @return array{0: Product, 1: Company}
     */
    private function createProductLinkedToClient(): array
    {
        $original = Product::factory()->create([
            'name' => 'Lens 30 8H1',
            'model_number' => '30 8H1-HDX',
            'status' => ProductStatus::ACTIVE,
        ]);

        $client = Company::factory()->create(['name' => 'Client Co']);

        CompanyProduct::create([
            'company_id' => $client->id,
            'product_id' => $original->id,
            'role' => 'client',
            'external_code' => '30 8H1',
            'external_name' => 'Client lens name',
            'external_description' => 'Client-facing description',
            'unit_price' => 1234,
            'currency_code' => 'USD',
            'is_preferred' => true,
        ]);

        return [$original, $client];
    }

    private function assertClonedPivotHasNoExternalIdentity(Product $clone, Company $client, Product $original): void
    {
        $clonePivot = CompanyProduct::where('product_id', $clone->id)
            ->where('company_id', $client->id)
            ->where('role', 'client')
            ->firstOrFail();

        // Identity fields must NOT carry over to the clone.
        $this->assertNull($clonePivot->external_code);
        $this->assertNull($clonePivot->external_name);
        $this->assertNull($clonePivot->external_description);

        // Commercial fields still carry over.
        $this->assertSame(1234, $clonePivot->unit_price);
        $this->assertSame('USD', $clonePivot->currency_code);
        $this->assertTrue($clonePivot->is_preferred);

        // Original pivot is untouched.
        $originalPivot = CompanyProduct::where('product_id', $original->id)
            ->where('company_id', $client->id)
            ->where('role', 'client')
            ->firstOrFail();
        $this->assertSame('30 8H1', $originalPivot->external_code);
    }
}
